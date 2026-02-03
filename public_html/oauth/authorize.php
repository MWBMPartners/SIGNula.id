<?php
/**
 * ============================================================================
 * 🔐 SIGNula - OAuth Authorization Endpoint
 * ============================================================================
 *
 * Purpose: Initiate OAuth authentication flow
 * PHP Version: 8.3+
 *
 * Usage:
 * - /oauth/authorize?provider=google
 * - /oauth/authorize?provider=microsoft
 * - /oauth/authorize?provider=facebook
 * - /oauth/authorize?provider=apple
 *
 * Optional parameters:
 * - &mode=link - For linking account (user must be logged in)
 * - &redirect=/path - Where to redirect after completion
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

try {
    // 📝 Get provider parameter
    $provider = $_GET['provider'] ?? '';

    if (empty($provider)) {
        throw new RuntimeException('Provider not specified');
    }

    // 🧹 Sanitize provider name
    $provider = strtolower(SecurityUtils::sanitizeString($provider));

    // ✅ Validate provider
    $allowedProviders = ['google', 'microsoft', 'facebook', 'apple'];

    if (!in_array($provider, $allowedProviders, true)) {
        throw new RuntimeException('Invalid provider: ' . $provider);
    }

    // 🔍 Check mode (sign-in or link)
    $mode = $_GET['mode'] ?? 'signin';

    if ($mode === 'link') {
        // 🔐 Linking mode requires authentication
        if (!Auth::isAuthenticated()) {
            $_SESSION['error'] = 'You must be logged in to link accounts';
            redirect('/login?redirect=' . urlencode('/oauth/authorize?provider=' . $provider . '&mode=link'));
        }
    }

    // 📍 Store redirect path
    if (!empty($_GET['redirect'])) {
        $_SESSION['oauth_redirect'] = $_GET['redirect'];
    }

    // 🔌 Load provider class
    $providerClass = ucfirst($provider) . 'OAuth';
    $providerFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR .
                   'auth' . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . $providerClass . '.php';

    if (!file_exists($providerFile)) {
        throw new RuntimeException("Provider not found: {$provider}");
    }

    require_once $providerFile;

    if (!class_exists($providerClass)) {
        throw new RuntimeException("Provider class not found: {$providerClass}");
    }

    // 🏗️ Instantiate provider
    $oauth = new $providerClass();

    // ✅ Check if provider is configured
    if (!$oauth->isConfigured()) {
        throw new RuntimeException($oauth->getDisplayName() . ' OAuth is not configured. Please contact the administrator.');
    }

    // 🔗 Generate authorization URL
    $authUrl = $oauth->getAuthorizationUrl();

    // 📝 Log activity
    if (Auth::isAuthenticated()) {
        ActivityLogger::log(
            Auth::getCurrentUser()['userID'],
            'oauth_initiated',
            'auth',
            'info',
            "Initiated OAuth flow for {$provider}",
            ['provider' => $provider, 'mode' => $mode]
        );
    } else {
        ActivityLogger::log(
            null,
            'oauth_initiated',
            'auth',
            'info',
            "Anonymous OAuth flow initiated for {$provider}",
            ['provider' => $provider]
        );
    }

    // 🚀 Redirect to provider's authorization page
    header('Location: ' . $authUrl);
    exit;

} catch (Exception $e) {
    // ❌ Error handling
    error_log('OAuth authorize error: ' . $e->getMessage());
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());

    // 🔙 Redirect to login with error
    $_SESSION['error'] = 'OAuth error: ' . $e->getMessage();
    redirect('/login');
}
