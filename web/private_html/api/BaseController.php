<?php
/**
 * ============================================================================
 * 🎮 SIGNula - Base API Controller
 * ============================================================================
 *
 * Base controller class for all API controllers.
 * Provides common functionality and utilities.
 *
 * Features:
 * - Request/response handling
 * - Input validation
 * - Authentication checking
 * - Error handling
 * - Pagination helpers
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
 * Class BaseController
 *
 * Base class for all API controllers
 */
abstract class BaseController
{
    /**
     * 🛣️ Router instance
     */
    protected Router $router;

    /**
     * 👤 Current authenticated user
     */
    protected ?array $currentUser = null;

    /**
     * 🏗️ Constructor
     */
    public function __construct()
    {
        // Router will be injected when needed
    }

    /**
     * 🔧 Set router instance
     *
     * @param Router $router Router instance
     * @return void
     */
    public function setRouter(Router $router): void
    {
        $this->router = $router;
    }

    /**
     * 📥 Get request input
     *
     * @param string|null $key Specific key to get
     * @param mixed $default Default value
     * @return mixed Input value or all input
     */
    protected function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->router->getRequestBody(true);
        }

        return $this->router->input($key, $default);
    }

    /**
     * 🔍 Get query parameter
     *
     * @param string $key Parameter key
     * @param mixed $default Default value
     * @return mixed Parameter value
     */
    protected function query(string $key, mixed $default = null): mixed
    {
        return $this->router->query($key, $default);
    }

    /**
     * 🆔 Get route parameter
     *
     * @param string $key Parameter key
     * @param mixed $default Default value
     * @return mixed Parameter value
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return $this->router->param($key, $default);
    }

    /**
     * ✅ Validate request input
     *
     * Automatically sends error response if validation fails
     *
     * @param array $rules Validation rules
     * @return array Validated data
     */
    protected function validate(array $rules): array
    {
        $data = $this->input();

        if (!is_array($data)) {
            Response::error('Invalid request data', Response::HTTP_BAD_REQUEST);
        }

        $validator = new Validator($data);

        if (!$validator->validate($rules)) {
            Response::validationError('Validation failed', $validator->errors());
        }

        return $validator->validated();
    }

    /**
     * 🔒 Require authentication
     *
     * Validates that user is authenticated
     * Exits with 401 if not authenticated
     *
     * @return array Current user data
     */
    protected function requireAuth(): array
    {
        if ($this->currentUser === null) {
            $this->currentUser = $this->getCurrentUser();

            if ($this->currentUser === null) {
                Response::unauthorized('Authentication required');
            }
        }

        return $this->currentUser;
    }

    /**
     * 👤 Get current authenticated user
     *
     * @return array|null User data or null
     */
    protected function getCurrentUser(): ?array
    {
        // Check session-based auth
        if (isset($_SESSION['user_id'])) {
            return $this->getUserById($_SESSION['user_id']);
        }

        // Check JWT token (if implemented)
        $token = $this->router->getBearerToken();
        if ($token) {
            return $this->getUserByToken($token);
        }

        // Check API key (if implemented)
        $apiKey = $this->getApiKey();
        if ($apiKey) {
            return $this->getUserByApiKey($apiKey);
        }

        return null;
    }

    /**
     * 🔍 Get user by ID
     *
     * @param int $userId User ID
     * @return array|null User data
     */
    private function getUserById(int $userId): ?array
    {
        try {
            $query = "SELECT * FROM tblUsers WHERE userID = ? AND accountStatus = 'active'";
            $result = Database::query($query, [$userId], 'i');

            if ($result && $user = $result->fetch_assoc()) {
                return $user;
            }

            return null;
        } catch (Exception $e) {
            ErrorLogger::log($e);
            return null;
        }
    }

    /**
     * 🎫 Get user by JWT token
     *
     * @param string $token JWT token
     * @return array|null User data
     */
    private function getUserByToken(string $token): ?array
    {
        try {
            // 🔐 Validate JWT format (header.payload.signature)
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                ErrorLogger::logError('JWT_ERROR', 'Invalid JWT format: expected 3 parts', __FILE__, __LINE__);
                return null;
            }

            [$headerB64, $payloadB64, $signatureB64] = $parts;

            // 🔓 Decode header and payload
            $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
            $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

            if (!$header || !$payload) {
                ErrorLogger::logError('JWT_ERROR', 'Failed to decode JWT header/payload', __FILE__, __LINE__);
                return null;
            }

            // ✅ Verify algorithm (only allow HS256)
            if (($header['alg'] ?? '') !== 'HS256') {
                ErrorLogger::logError('JWT_ERROR', 'Unsupported JWT algorithm: ' . ($header['alg'] ?? 'none'), __FILE__, __LINE__);
                return null;
            }

            // 🔑 Verify signature
            $jwtSecret = getSetting('security.jwt_secret', ENCRYPTION_KEY);
            $validSignature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $jwtSecret, true);
            $validSignatureB64 = rtrim(strtr(base64_encode($validSignature), '+/', '-_'), '=');

            if (!hash_equals($validSignatureB64, $signatureB64)) {
                ErrorLogger::logError('JWT_ERROR', 'Invalid JWT signature', __FILE__, __LINE__);
                return null;
            }

            // ⏰ Check expiration
            $now = time();
            if (isset($payload['exp']) && $now >= $payload['exp']) {
                ErrorLogger::logError('JWT_ERROR', 'JWT token expired', __FILE__, __LINE__);
                return null;
            }

            // ⏳ Check not-before time
            if (isset($payload['nbf']) && $now < $payload['nbf']) {
                ErrorLogger::logError('JWT_ERROR', 'JWT token not yet valid', __FILE__, __LINE__);
                return null;
            }

            // 👤 Get user by ID from token payload
            $userID = $payload['sub'] ?? $payload['user_id'] ?? null;
            if (!$userID) {
                ErrorLogger::logError('JWT_ERROR', 'JWT missing user identifier (sub/user_id)', __FILE__, __LINE__);
                return null;
            }

            // 🔍 Fetch user from database
            $user = Database::fetchOne(
                "SELECT * FROM tblUsers WHERE userID = ? AND accountStatus = 'active'",
                [(int)$userID],
                'i'
            );

            if (!$user) {
                ErrorLogger::logError('JWT_ERROR', "User not found or inactive: {$userID}", __FILE__, __LINE__);
                return null;
            }

            return $user;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', 'JWT validation error: ' . $e->getMessage(), $e->getFile(), $e->getLine());
            return null;
        }
    }

    /**
     * 🔑 Get user by API key
     *
     * @param string $apiKey API key
     * @return array|null User data
     */
    private function getUserByApiKey(string $apiKey): ?array
    {
        try {
            $query = "
                SELECT u.*
                FROM tblUsers u
                INNER JOIN tblAPIKeys k ON u.userID = k.userID
                WHERE k.apiKey = ?
                AND k.isActive = 1
                AND (k.expiresAt IS NULL OR k.expiresAt > NOW())
                AND u.accountStatus = 'active'
            ";

            $result = Database::query($query, [$apiKey], 's');

            if ($result && $user = $result->fetch_assoc()) {
                return $user;
            }

            return null;
        } catch (Exception $e) {
            ErrorLogger::log($e);
            return null;
        }
    }

    /**
     * 🔑 Get API key from request
     *
     * @return string|null API key
     */
    private function getApiKey(): ?string
    {
        // Check X-API-Key header
        $headers = getallheaders();
        if (isset($headers['X-API-Key'])) {
            return $headers['X-API-Key'];
        }

        // Check query parameter
        return $_GET['api_key'] ?? null;
    }

    /**
     * 📄 Paginate query results
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @param string $types Parameter types
     * @param int|null $page Page number (from query string if null)
     * @param int $perPage Items per page (default: 25)
     * @return array Pagination data
     */
    protected function paginate(
        string $query,
        array $params = [],
        string $types = '',
        ?int $page = null,
        int $perPage = 25
    ): array {
        // Get page from query string if not provided
        if ($page === null) {
            $page = max(1, (int) $this->query('page', 1));
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage)); // Cap at 100 items per page

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM (" . $query . ") as count_table";
        $countResult = Database::query($countQuery, $params, $types);
        $total = 0;

        if ($countResult && $row = $countResult->fetch_assoc()) {
            $total = (int) $row['total'];
        }

        // Calculate offset
        $offset = ($page - 1) * $perPage;

        // Add LIMIT and OFFSET to query
        $paginatedQuery = $query . " LIMIT ? OFFSET ?";
        $paginatedParams = array_merge($params, [$perPage, $offset]);
        $paginatedTypes = $types . 'ii';

        // Execute paginated query
        $result = Database::query($paginatedQuery, $paginatedParams, $paginatedTypes);
        $items = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 📤 Send paginated response
     *
     * @param array $items Array of items
     * @param int $total Total item count
     * @param int $page Current page
     * @param int $perPage Items per page
     * @param string $message Success message
     * @return void (exits via Response)
     */
    protected function sendPaginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        string $message = 'Success'
    ): void {
        Response::paginated($items, $total, $page, $perPage, $message);
    }

    /**
     * 🚀 Send success response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $statusCode HTTP status code
     * @return void (exits via Response)
     */
    protected function success(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = Response::HTTP_OK
    ): void {
        Response::success($data, $message, $statusCode);
    }

    /**
     * ❌ Send error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array $errors Error details
     * @return void (exits via Response)
     */
    protected function error(
        string $message,
        int $statusCode = Response::HTTP_BAD_REQUEST,
        array $errors = []
    ): void {
        Response::error($message, $statusCode, $errors);
    }

    /**
     * ✅ Send created response
     *
     * @param mixed $data Created resource data
     * @param string $message Success message
     * @return void (exits via Response)
     */
    protected function created(mixed $data, string $message = 'Resource created successfully'): void
    {
        Response::created($data, $message);
    }

    /**
     * 🗑️ Send no content response
     *
     * @return void (exits via Response)
     */
    protected function noContent(): void
    {
        Response::noContent();
    }

    /**
     * 🔒 Send unauthorized response
     *
     * @param string $message Error message
     * @return void (exits via Response)
     */
    protected function unauthorized(string $message = 'Authentication required'): void
    {
        Response::unauthorized($message);
    }

    /**
     * 🚫 Send forbidden response
     *
     * @param string $message Error message
     * @return void (exits via Response)
     */
    protected function forbidden(string $message = 'Access denied'): void
    {
        Response::forbidden($message);
    }

    /**
     * 🔍 Send not found response
     *
     * @param string $message Error message
     * @return void (exits via Response)
     */
    protected function notFound(string $message = 'Resource not found'): void
    {
        Response::notFound($message);
    }
}

// ✅ BaseController class loaded successfully
