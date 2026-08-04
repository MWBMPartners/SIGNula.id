<?php
/**
 * ============================================================================
 * 🔐 SIGNula - OAuth Authorization Endpoint
 * ============================================================================
 *
 * Purpose: Initiate OAuth 2.0 authorization flow for delegate email sending
 * PHP Version: 8.3+
 *
 * URL: /oauth/authorize
 * Method: GET
 * Parameters:
 *   - provider: Provider name (microsoft_graph, google_workspace)
 *   - mailbox: Mailbox email to authorize (optional)
 *   - purpose: 'email' for email delegation, 'signin' for account linking
 *
 * @package    SIGNula
 * @subpackage OAuth
 * @version    2.0.0
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📝 Get parameters (read purpose BEFORE auth check)
$provider = $_GET['provider'] ?? '';
$mailboxEmail = $_GET['mailbox'] ?? '';
$purpose = $_GET['purpose'] ?? 'email';  // 'email' or 'signin'

// 🔐 Require authentication for email delegation only
// Sign-in purpose must allow unauthenticated users (that's the whole point!)
if ($purpose === 'email') {
    if (!Auth::isAuthenticated()) {
        header('Location: /login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

$userID = Auth::isAuthenticated() ? Auth::getCurrentUserID() : null;

// ✅ Validate provider
if (empty($provider)) {
    die('Error: Provider parameter required');
}

// 🔍 Handle different purposes
if ($purpose === 'email') {
    // 📧 Email delegation authorization
    if (empty($mailboxEmail)) {
        // Use user's email if not specified
        $user = Auth::getCurrentUser();
        $mailboxEmail = $user['email'] ?? '';
    }

    if (empty($mailboxEmail)) {
        die('Error: Mailbox email required for email delegation');
    }

    // 🔐 Load OAuth flow handler
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthFlowHandler.php';

    // 📝 Store purpose in session for callback
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['oauth_purpose'] = 'email';

    // 🔗 Generate authorization URL
    $result = OAuthFlowHandler::generateAuthorizationUrl($provider, $userID, $mailboxEmail);

    if (isset($result['error'])) {
        // ❌ Error generating URL
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>OAuth Authorization Error - SIGNula</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    max-width: 600px;
                    margin: 50px auto;
                    padding: 20px;
                    background-color: #f5f5f5;
                }
                .error-box {
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    border-left: 4px solid #e74c3c;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                h1 {
                    color: #e74c3c;
                    margin-top: 0;
                }
                .error-message {
                    color: #333;
                    margin: 15px 0;
                }
                .back-link {
                    display: inline-block;
                    margin-top: 20px;
                    padding: 10px 20px;
                    background: #3498db;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                }
                .back-link:hover {
                    background: #2980b9;
                }
            </style>
        </head>
        <body>
            <div class="error-box">
                <h1>Authorization Error</h1>
                <p class="error-message"><?php echo htmlspecialchars($result['error']); ?></p>
                <a href="/settings/connected-accounts" class="back-link">← Back to Settings</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // ✅ Redirect to provider's authorization page
    header('Location: ' . $result['url']);
    exit;

} else {
    // 🔐 Account sign-in/linking (original functionality)
    // Load existing OAuth provider logic...

    try {
        // 🧹 Sanitize provider name
        $provider = strtolower(SecurityUtils::sanitizeString($provider));

        // ✅ Validate provider
        // 🔌 FG-008: 'oidc' (generic OpenID Connect) added — see the
        // "Provider class resolution" fallback below. 'lastpass' is ALSO
        // allowed here so a DISABLED settings template (migration 049) can
        // become a working named generic-OIDC instance the moment an admin
        // fills in its issuer/client_id/client_secret, with no further code
        // change — see GenericOidcProvider.php's file-level docblock.
        $allowedProviders = ['google', 'microsoft', 'facebook', 'apple', 'oidc', 'lastpass'];

        if (!in_array($provider, $allowedProviders, true)) {
            throw new RuntimeException('Invalid provider: ' . $provider);
        }

        // 🔌 Provider class resolution
        // Convention: provider key 'foo' -> class 'FooOAuth' in
        // providers/FooOAuth.php (GoogleOAuth, MicrosoftOAuth, YahooOAuth, …).
        // FG-008: when NO dedicated class file exists for the requested key,
        // fall back to the config-driven generic OpenID Connect connector
        // (GenericOidcProvider), constructed with the provider key itself so
        // it reads its settings from oauth.{provider}.* — see that class's
        // file-level "MULTIPLE NAMED INSTANCES" docblock section.
        $providersDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR .
                       'auth' . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR;
        $providerClass = ucfirst($provider) . 'OAuth';
        $providerFile = $providersDir . $providerClass . '.php';

        if (file_exists($providerFile)) {
            require_once $providerFile;

            if (!class_exists($providerClass)) {
                throw new RuntimeException("Provider class not found: {$providerClass}");
            }

            // 🏗️ Instantiate dedicated provider
            $oauth = new $providerClass();
        } elseif (file_exists($providersDir . 'GenericOidcProvider.php')) {
            require_once $providersDir . 'GenericOidcProvider.php';

            // 🏗️ Instantiate generic OIDC connector for this provider key
            $oauth = new GenericOidcProvider($provider);
        } else {
            throw new RuntimeException("Provider not found: {$provider}");
        }

        // ✅ Check if provider is configured
        if (!$oauth->isConfigured()) {
            throw new RuntimeException($oauth->getDisplayName() . ' OAuth is not configured. Please contact the administrator.');
        }

        // 🔗 Generate authorization URL
        $authUrl = $oauth->getAuthorizationUrl();

        // 📝 Log activity (only if user is authenticated)
        if ($userID) {
            ActivityLogger::log($userID, 'oauth_initiated', 'auth', 'info',
                "OAuth authorization initiated for {$provider}", [
                    'provider' => $provider,
                    'purpose' => 'signin'
                ]);
        }

        // 🚀 Redirect to provider's authorization page
        header('Location: ' . $authUrl);
        exit;

    } catch (Exception $e) {
        // ❌ Error handling
        error_log('OAuth authorize error: ' . $e->getMessage());
        ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());

        // 🔙 Redirect to login with error (don't expose internal details)
        $_SESSION['error'] = 'Unable to connect to the authentication provider. Please try again.';
        header('Location: /login');
        exit;
    }
}
