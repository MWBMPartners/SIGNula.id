<?php
/**
 * ============================================================================
 * 🚀 SIGNula - RESTful API Entry Point (v1)
 * ============================================================================
 *
 * Main entry point for SIGNula RESTful API v1.
 * All API requests are routed through this file.
 *
 * API Documentation: https://SIGNula.id/api/docs
 *
 * Authentication:
 * - Session-based (via cookies)
 * - Bearer token (Authorization: Bearer {token})
 * - API key (X-API-Key header or ?api_key= parameter)
 *
 * Response Format:
 * {
 *   "success": true|false,
 *   "message": "Message",
 *   "data": {...},
 *   "meta": {
 *     "timestamp": "ISO 8601",
 *     "version": "v1",
 *     "request_id": "unique-id"
 *   }
 * }
 *
 * @package    SIGNula
 * @subpackage API
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🔧 Initialize application
define('SIGNULA_INIT', true);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📡 Load API classes
require_once INCLUDES_DIR . '/api/Response.php';
require_once INCLUDES_DIR . '/api/Router.php';
require_once INCLUDES_DIR . '/api/Validator.php';
require_once INCLUDES_DIR . '/api/BaseController.php';

// 🔒 Load security middleware
require_once PRIVATE_DIR . '/api/RateLimitMiddleware.php';
require_once PRIVATE_DIR . '/api/APIKeyMiddleware.php';

// 📦 Load controllers
require_once INCLUDES_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'AuthController.php';
// 🎫 JWT bearer-auth controller (G-003): /auth/token, /auth/refresh, /auth/revoke.
//    Loaded with a resilient path lookup: the deploy maps _private/_includes onto
//    private_html, but we also try the in-repo private_html path and finally rely
//    on the spl autoloader (which now scans api/controllers/) so `new
//    JwtAuthController()` resolves regardless of layout.
(function (): void {
    $candidates = [
        PRIVATE_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'JwtAuthController.php',
        ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'JwtAuthController.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
    // Not found via explicit path — the autoloader will resolve it on first use.
})();
require_once INCLUDES_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'UserController.php';
require_once INCLUDES_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'MFAController.php';
require_once INCLUDES_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'OAuthController.php';
require_once PRIVATE_DIR . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'UsageController.php';

// 🛣️ Create router instance
$router = new Router();

// 🔧 Set API version
Response::setVersion('v1');

// ============================================================================
// 🔒 SECURITY MIDDLEWARE
// ============================================================================

// 🗄️ CAP-API Bucket B prerequisite fix: `$db` was never defined anywhere in
// this file before being passed to the two middlewares below. Both
// constructors fall back to `global $db;` when their argument is null, but
// nothing in the whole request lifecycle EVER set a global `$db` either
// (confirmed — no `$GLOBALS['db']` assignment exists anywhere in this
// codebase) — so `$this->db` ended up null in BOTH middlewares, and the
// very first DB query inside RateLimiter::loadConfig()/isEnabled()
// (`$this->db->prepare(...)`) fataled with "Call to a member function
// prepare() on null" on literally EVERY /api/v1/* request, before the
// router ever dispatched to a real handler. Defining it here the SAME way
// dozens of other standalone/AJAX handlers in this codebase already do
// (`Database::getInstance()` — a DatabaseConnection proxy with the same
// prepare()/query() surface as a raw mysqli link) is what makes rate
// limiting and API-key auth actually function for the REST API at all.
// @see web/_config/database.php — Database::getInstance()/DatabaseConnection
$db = Database::getInstance();

// Initialize security middlewares
$rateLimitMiddleware = new RateLimitMiddleware($db);
$apiKeyMiddleware = new APIKeyMiddleware($db);

// Apply rate limiting to ALL requests
$rateLimitMiddleware->handle();

// Apply API key authentication (optional - allows both authenticated and unauthenticated)
// Individual routes can require API key authentication by calling $apiKeyMiddleware->handle(true)
$apiKeyMiddleware->handle(false);

// ============================================================================
// 🔐 AUTHENTICATION ROUTES
// ============================================================================

$router->group('/api/v1/auth', function($router) {
    // User registration and login
    $router->post('/register', 'AuthController@register');
    $router->post('/login', 'AuthController@login');
    $router->post('/logout', 'AuthController@logout');

    // 🎫 JWT bearer-token endpoints (G-003).
    //    /token  — issue an access + refresh pair after a PRIMARY credential
    //              (email/password via Auth, OR an authenticated session) is
    //              verified. The userID NEVER comes from client input.
    //    /refresh — single-use rotation + reuse-detection (repoints the former
    //              session-refresh stub AuthController@refresh to the JWT flow).
    //    /revoke — RFC 7009 idempotent revocation (denylist jti / revoke family).
    $router->post('/token', 'JwtAuthController@issueToken');
    $router->post('/refresh', 'JwtAuthController@refreshToken');
    $router->post('/revoke', 'JwtAuthController@revokeToken');

    // Email verification
    $router->post('/verify-email', 'AuthController@verifyEmail');
    $router->get('/verify-email', 'AuthController@verifyEmail'); // Support GET for email links

    // Password reset
    $router->post('/forgot-password', 'AuthController@forgotPassword');
    $router->post('/reset-password', 'AuthController@resetPassword');
});

// ============================================================================
// 👤 USER MANAGEMENT ROUTES
// ============================================================================

$router->group('/api/v1/user', function($router) {
    // Profile management
    $router->get('/profile', 'UserController@getProfile');
    $router->put('/profile', 'UserController@updateProfile');

    // Session management
    $router->get('/sessions', 'UserController@getSessions');
    $router->delete('/session/{id}', 'UserController@deleteSession');

    // Activity log
    $router->get('/activity', 'UserController@getActivity');

    // Preferences
    $router->get('/preferences', 'UserController@getPreferences');
    $router->put('/preferences', 'UserController@updatePreferences');

    // Account changes
    $router->post('/change-password', 'UserController@changePassword');
    $router->post('/change-email', 'UserController@changeEmail');

    // 🖼️ Avatar / Profile picture management
    $router->post('/avatar', 'UserController@uploadAvatar');
    $router->delete('/avatar', 'UserController@deleteAvatar');
    $router->put('/avatar/source', 'UserController@setAvatarSource');
    $router->get('/avatar/sources', 'UserController@getAvatarSources');
    $router->put('/avatar/fallback-priority', 'UserController@updateFallbackPriority');
});

// ============================================================================
// 🔐 MFA (MULTI-FACTOR AUTHENTICATION) ROUTES
// ============================================================================

$router->group('/api/v1/mfa', function($router) {
    // MFA management
    $router->post('/enable', 'MFAController@enable');
    $router->post('/disable', 'MFAController@disable');
    $router->post('/verify', 'MFAController@verify');
    $router->get('/setup', 'MFAController@getSetup');

    // Backup codes
    $router->get('/backup-codes', 'MFAController@getBackupCodes');
    $router->post('/backup-codes/regenerate', 'MFAController@regenerateBackupCodes');
});

// ============================================================================
// 🔗 OAUTH ACCOUNT LINKING ROUTES
// ============================================================================

$router->group('/api/v1/oauth', function($router) {
    // Provider information
    $router->get('/providers', 'OAuthController@getProviders');

    // Account management
    $router->get('/linked', 'OAuthController@getLinkedAccounts');
    $router->post('/link', 'OAuthController@linkAccount');
    $router->delete('/unlink/{provider}', 'OAuthController@unlinkAccount');
    $router->post('/set-primary', 'OAuthController@setPrimary');
});

// ============================================================================
// 📊 USAGE BILLING ROUTES
// ============================================================================

$router->group('/api/v1/usage', function($router) {
    // 📊 Usage recording (requires API key — for partner usage submission)
    $router->post('/record', 'UsageController@recordUsage');
    $router->post('/batch', 'UsageController@recordBatchUsage');

    // 📊 Usage data retrieval (requires user authentication)
    $router->get('/current', 'UsageController@getCurrentUsage');
    $router->get('/history', 'UsageController@getUsageHistory');
    $router->get('/projected-cost', 'UsageController@getProjectedCost');
    $router->get('/billing-summaries', 'UsageController@getBillingSummaries');

    // 📋 Usage metrics (public — no auth required)
    $router->get('/metrics', 'UsageController@getMetrics');

    // 🔀 Billing mode management (requires user authentication)
    $router->put('/billing-mode', 'UsageController@changeBillingMode');
});

// ============================================================================
// 🔍 UTILITY ROUTES
// ============================================================================

// Health check endpoint
$router->get('/api/v1/health', function($params) {
    Response::success([
        'status' => 'healthy',
        'timestamp' => gmdate('c'),
        'version' => 'v1',
    ], 'API is operational');
});

// API info endpoint
$router->get('/api/v1/info', function($params) {
    Response::success([
        'name' => 'SIGNula API',
        'version' => 'v1',
        'documentation' => 'https://SIGNula.id/api/docs',
        'status' => 'active',
    ], 'API information');
});

// ============================================================================
// 🚀 DISPATCH REQUEST
// ============================================================================

try {
    $router->dispatch();

    // 📊 Log API usage on success (200-299 status codes)
    $statusCode = http_response_code();
    if ($statusCode >= 200 && $statusCode < 300) {
        $apiKeyMiddleware->logUsage($statusCode);
    }
} catch (Exception $e) {
    // 💥 Global error handler
    ErrorLogger::log($e);

    // Log API usage on error
    $apiKeyMiddleware->logUsage(500, 'INTERNAL_ERROR');

    Response::internalError('An unexpected error occurred', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
