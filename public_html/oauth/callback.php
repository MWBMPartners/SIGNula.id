<?php
/**
 * ============================================================================
 * 🔐 SIGNula - OAuth Callback Handler
 * ============================================================================
 *
 * Purpose: Process OAuth authentication responses from providers
 * PHP Version: 8.3+
 *
 * Handles:
 * - Authorization code exchange
 * - User information retrieval
 * - Account creation or linking
 * - Session establishment
 * - Error handling and user feedback
 *
 * Supports: Google, Microsoft, Facebook, Apple (GET and POST)
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📝 Handle OAuth callback
$error = null;
$success = null;

try {
    // 🔍 Determine request method (Apple uses POST, others use GET)
    $requestData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

    // ❌ Check for OAuth errors
    if (!empty($requestData['error'])) {
        $errorCode = $requestData['error'];
        $errorDescription = $requestData['error_description'] ?? 'Authentication failed';

        // 📝 Log error
        ActivityLogger::log(null, 'oauth_error', 'auth', 'warning',
            "OAuth error: {$errorCode}", [
                'error' => $errorCode,
                'description' => $errorDescription
            ]);

        // 👤 User-friendly error messages
        $errorMessages = [
            'access_denied' => 'You declined the authorization request. Please try again if you want to sign in.',
            'invalid_request' => 'Invalid OAuth request. Please try again.',
            'unauthorized_client' => 'This application is not authorized. Please contact support.',
            'unsupported_response_type' => 'OAuth configuration error. Please contact support.',
            'invalid_scope' => 'Invalid permissions requested. Please contact support.',
            'server_error' => 'The authentication provider encountered an error. Please try again.',
            'temporarily_unavailable' => 'The authentication service is temporarily unavailable. Please try again later.',
        ];

        $error = $errorMessages[$errorCode] ?? $errorDescription;

        // 🔙 Redirect to login with error
        redirect('/login?oauth_error=' . urlencode($error));
    }

    // ✅ Validate required parameters
    if (empty($requestData['code']) || empty($requestData['state'])) {
        throw new RuntimeException('Missing required OAuth parameters');
    }

    $authorizationCode = $requestData['code'];
    $state = $requestData['state'];

    // 🔍 Verify state token (CSRF protection)
    if (empty($_SESSION['oauth_state'])) {
        throw new RuntimeException('OAuth state not found in session');
    }

    $storedState = $_SESSION['oauth_state'];

    // ⏰ Check expiration
    if (time() > $storedState['expires']) {
        unset($_SESSION['oauth_state']);
        throw new RuntimeException('OAuth state expired');
    }

    // ✅ Verify state matches
    if (!hash_equals($storedState['token'], $state)) {
        unset($_SESSION['oauth_state']);
        throw new RuntimeException('Invalid OAuth state token');
    }

    $provider = $storedState['provider'];
    unset($_SESSION['oauth_state']); // Clear state after verification

    // 🔌 Load provider class
    $providerClass = ucfirst($provider) . 'OAuth';
    $providerFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_includes' . DIRECTORY_SEPARATOR .
                   'auth' . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . $providerClass . '.php';

    if (!file_exists($providerFile)) {
        throw new RuntimeException("Provider not found: {$provider}");
    }

    require_once $providerFile;

    if (!class_exists($providerClass)) {
        throw new RuntimeException("Provider class not found: {$providerClass}");
    }

    $oauth = new $providerClass();

    // 🔄 Exchange authorization code for access token
    $tokenData = $oauth->exchangeCodeForToken($authorizationCode);

    // 👤 Get user information from provider
    if ($provider === 'apple') {
        // Apple uses ID token
        $userData = $oauth->getUserInfo($tokenData['id_token']);

        // 📝 Process additional user data from authorization response (only on first sign-in)
        if (!empty($requestData['user'])) {
            $authUserData = $oauth->processAuthorizationResponse($requestData);
            $userData = array_merge($userData, $authUserData);
        }
    } else {
        // Standard OAuth providers use access token
        $userData = $oauth->getUserInfo($tokenData['access_token']);
    }

    // 📧 Validate email exists
    if (empty($userData['email'])) {
        throw new RuntimeException('Email address not provided by OAuth provider. Please ensure you grant email permission.');
    }

    // 🔍 Check if this is account linking (user already logged in)
    $isLinking = Auth::isAuthenticated();
    $currentUser = $isLinking ? Auth::getCurrentUser() : null;

    if ($isLinking) {
        // 🔗 ACCOUNT LINKING MODE
        // User is already logged in and wants to link this OAuth account

        $currentUserID = $currentUser['userID'];

        // ✅ Check if this provider account is already linked to another user
        $existingLink = $oauth->findUserByProviderAccount($userData['provider_id']);

        if ($existingLink && $existingLink !== $currentUserID) {
            throw new RuntimeException('This ' . $oauth->getDisplayName() . ' account is already linked to another SIGNula account.');
        }

        // 🔗 Link account
        $linked = $oauth->linkAccount($currentUserID, $userData, $tokenData);

        if (!$linked) {
            throw new RuntimeException('Failed to link ' . $oauth->getDisplayName() . ' account.');
        }

        // ✅ Success - redirect to account settings
        $_SESSION['success'] = $oauth->getDisplayName() . ' account linked successfully!';
        redirect('/account?tab=linked-accounts');

    } else {
        // 🔐 SIGN-IN MODE
        // User is not logged in, attempting to sign in with OAuth

        // 🔍 Check if provider account is already linked to a SIGNula user
        $existingUserID = $oauth->findUserByProviderAccount($userData['provider_id']);

        if ($existingUserID) {
            // ✅ Provider account already linked - sign in existing user
            $user = Database::fetchOne(
                "SELECT * FROM tblUsers WHERE userID = ? AND deletedAt IS NULL",
                [$existingUserID],
                'i'
            );

            if (!$user) {
                throw new RuntimeException('User account not found');
            }

            // 🔄 Update OAuth tokens
            $oauth->linkAccount($existingUserID, $userData, $tokenData);

            // 🔐 Complete login
            Auth::completeLogin($existingUserID, false);

            // 📝 Log activity
            ActivityLogger::log($existingUserID, 'oauth_login', 'auth', 'info',
                "Logged in via {$provider}", ['provider' => $provider]);

            // ✅ Redirect to dashboard
            redirect('/dashboard');

        } else {
            // 🆕 NEW USER - Check if email already exists with password-based account

            $existingEmailUser = Database::fetchOne(
                "SELECT userID, email, username FROM tblUsers WHERE email = ? AND deletedAt IS NULL",
                [$userData['email']],
                's'
            );

            if ($existingEmailUser) {
                // 📧 Email exists with password account
                // Option 1: Auto-link if email is verified by provider
                // Option 2: Require user to log in first then link

                if ($userData['email_verified']) {
                    // ✅ Email verified by provider - auto-link and sign in
                    $userID = $existingEmailUser['userID'];

                    // 🔗 Link OAuth account
                    $oauth->linkAccount($userID, $userData, $tokenData);

                    // 🔐 Complete login
                    Auth::completeLogin($userID, false);

                    // 📝 Log activity
                    ActivityLogger::log($userID, 'oauth_auto_linked', 'auth', 'info',
                        "OAuth account auto-linked via verified email", ['provider' => $provider]);

                    // ✅ Redirect with message
                    $_SESSION['success'] = $oauth->getDisplayName() . ' account linked to your existing SIGNula account!';
                    redirect('/dashboard');

                } else {
                    // ⚠️ Email not verified - require manual linking
                    $_SESSION['info'] = 'An account with this email already exists. Please log in with your password and link your ' .
                                       $oauth->getDisplayName() . ' account from account settings.';
                    redirect('/login?email=' . urlencode($userData['email']));
                }

            } else {
                // 🆕 CREATE NEW USER ACCOUNT

                // 📝 Generate username from email or name
                $baseUsername = !empty($userData['first_name'])
                    ? strtolower($userData['first_name'] . substr($userData['last_name'] ?? '', 0, 1))
                    : strtolower(explode('@', $userData['email'])[0]);

                $baseUsername = preg_replace('/[^a-z0-9]/', '', $baseUsername);

                // 🔍 Ensure username is unique
                $username = $baseUsername;
                $counter = 1;
                while (true) {
                    $exists = Database::fetchOne(
                        "SELECT userID FROM tblUsers WHERE username = ?",
                        [$username],
                        's'
                    );

                    if (!$exists) {
                        break;
                    }

                    $username = $baseUsername . $counter;
                    $counter++;
                }

                // 👤 Create new user account
                $displayName = $userData['name'] ?: ($userData['first_name'] . ' ' . $userData['last_name']);
                $displayName = trim($displayName) ?: $username;

                $userUUID = bin2hex(random_bytes(16));

                $insertQuery = "
                    INSERT INTO tblUsers
                    (userUUID, username, email, displayName, passwordHash,
                     emailVerified, accountStatus, createdAt)
                    VALUES (?, ?, ?, ?, NULL, ?, 'active', NOW())
                ";

                $emailVerified = $userData['email_verified'] ? 1 : 0;

                $userID = Database::insert(
                    $insertQuery,
                    [$userUUID, $username, $userData['email'], $displayName, $emailVerified],
                    'ssssi'
                );

                if (!$userID) {
                    throw new RuntimeException('Failed to create user account');
                }

                // 🔗 Link OAuth account
                $oauth->linkAccount($userID, $userData, $tokenData);

                // 📝 Log activity
                ActivityLogger::log($userID, 'oauth_registration', 'auth', 'info',
                    "New account created via {$provider}", ['provider' => $provider]);

                // 🔐 Complete login
                Auth::completeLogin($userID, false);

                // ✅ Redirect to dashboard with welcome message
                $_SESSION['success'] = 'Welcome to SIGNula! Your account has been created successfully.';
                redirect('/dashboard');
            }
        }
    }

} catch (Exception $e) {
    // ❌ Error handling
    error_log('OAuth callback error: ' . $e->getMessage());
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());

    // 👤 User-friendly error message
    $error = 'Authentication failed: ' . $e->getMessage();

    // 🔙 Redirect to login with error
    redirect('/login?oauth_error=' . urlencode($error));
}

// 🔙 Fallback redirect (should not reach here)
redirect('/login');
