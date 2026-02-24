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
require_once INCLUDES_DIR . '/api/controllers/AuthController.php';
require_once INCLUDES_DIR . '/api/controllers/UserController.php';
require_once INCLUDES_DIR . '/api/controllers/MFAController.php';
require_once INCLUDES_DIR . '/api/controllers/OAuthController.php';

// 🛣️ Create router instance
$router = new Router();

// 🔧 Set API version
Response::setVersion('v1');

// ============================================================================
// 🔒 SECURITY MIDDLEWARE
// ============================================================================

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
    $router->post('/refresh', 'AuthController@refresh');

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
