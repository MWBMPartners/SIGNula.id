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

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📝 Handle OAuth callback
$error = null;
$success = null;

try {
    // 🔍 Determine request method (Apple uses POST, others use GET)
    $requestData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

    // 🎯 Check if this is email delegation callback (vs account sign-in)
    // Email delegation uses state parameter from OAuthFlowHandler
    if (!empty($requestData['state']) && !empty($requestData['code'])) {
        // 🔍 Load OAuth flow handler to check if this is email delegation
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'OAuthFlowHandler.php';

        // 🔐 Validate state token format (email delegation uses specific format)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check if state exists in our OAuth flow handler's session storage
        if (isset($_SESSION['oauth_state'][$requestData['state']])) {
            // ✅ This is an email delegation callback
            $provider = $_GET['provider'] ?? '';
            if (empty($provider)) {
                // Try to determine provider from state
                $stateData = $_SESSION['oauth_state'][$requestData['state']];
                $provider = $stateData['provider'] ?? '';
            }

            $code = $requestData['code'];
            $state = $requestData['state'];
            $error = $requestData['error'] ?? null;

            // 🔄 Handle email delegation callback
            $result = OAuthFlowHandler::handleCallback($provider, $code, $state, $error);

            if (isset($result['error'])) {
                // ❌ Error - redirect to settings with error
                $_SESSION['error'] = 'Failed to connect account: ' . $result['error'];
                header('Location: /settings/connected-accounts');
                exit;
            }

            // ✅ Success - redirect to settings with success message
            $_SESSION['success'] = 'Email account connected successfully! You can now send emails from this mailbox.';
            header('Location: /settings/connected-accounts');
            exit;
        }
    }

    // 🔐 If we reach here, this is a regular account sign-in callback (not email delegation)
    // Continue with existing sign-in logic...

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

    // 🔌 Provider class resolution
    // Convention: provider key 'foo' -> class 'FooOAuth' in
    // providers/FooOAuth.php (GoogleOAuth, MicrosoftOAuth, YahooOAuth, …).
    // FG-008: when NO dedicated class file exists for the requested key,
    // fall back to the config-driven generic OpenID Connect connector
    // (GenericOidcProvider), constructed with the provider key itself so it
    // reads its settings from oauth.{provider}.* — this is what lets an
    // admin register additional named generic-OIDC identity providers
    // (the single 'oidc' slot, a 'lastpass' template, or any other key)
    // purely via settings, with no matching PHP class required. See
    // GenericOidcProvider.php's file-level "MULTIPLE NAMED INSTANCES"
    // docblock section, and the matching fallback in oauth/authorize.php.
    $providersDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR .
                   'auth' . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR;
    $providerClass = ucfirst($provider) . 'OAuth';
    $providerFile = $providersDir . $providerClass . '.php';

    if (file_exists($providerFile)) {
        require_once $providerFile;

        if (!class_exists($providerClass)) {
            throw new RuntimeException("Provider class not found: {$providerClass}");
        }

        $oauth = new $providerClass();
    } elseif (file_exists($providersDir . 'GenericOidcProvider.php')) {
        require_once $providersDir . 'GenericOidcProvider.php';

        $oauth = new GenericOidcProvider($provider);
    } else {
        throw new RuntimeException("Provider not found: {$provider}");
    }

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

            // 🔐 Complete login — B-055: loginOAuth() can fail to create a
            // session (e.g. a DB error); if it does, we must NOT proceed to
            // redirect to /dashboard as if the user were logged in. Throwing
            // here routes through this file's existing catch (Exception $e)
            // block below, which logs the failure and redirects to /login
            // with a generic error — consistent with the other
            // RuntimeException throws already used in this file.
            if (!Auth::loginOAuth($existingUserID, false)) {
                throw new RuntimeException('Failed to complete login session for user account.');
            }

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

                    // 🔐 Complete login — B-055: see the matching guard above;
                    // don't fall through to a "success" redirect without a
                    // real session.
                    if (!Auth::loginOAuth($userID, false)) {
                        throw new RuntimeException('Failed to complete login session for user account.');
                    }

                    // 📝 Log activity
                    ActivityLogger::log($userID, 'oauth_auto_linked', 'auth', 'info',
                        "OAuth account auto-linked via verified email", ['provider' => $provider]);

                    // ✅ Redirect with message
                    $_SESSION['success'] = $oauth->getDisplayName() . ' account linked to your existing SIGNula account!';
                    redirect('/dashboard');

                } else {
                    // ⚠️ Email not verified - require manual linking
                    // Store email in session (not URL) to avoid exposing PII in browser history/logs
                    $_SESSION['info'] = 'An account with this email already exists. Please log in with your password and link your ' .
                                       $oauth->getDisplayName() . ' account from account settings.';
                    $_SESSION['prefill_email'] = $userData['email'];
                    redirect('/login');
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

                Database::query(
                    $insertQuery,
                    [$userUUID, $username, $userData['email'], $displayName, $emailVerified],
                    'ssssi'
                );

                $userID = Database::getLastInsertId();

                if (!$userID) {
                    throw new RuntimeException('Failed to create user account');
                }

                // 🔗 Link OAuth account
                $oauth->linkAccount($userID, $userData, $tokenData);

                // 📝 Log activity
                ActivityLogger::log($userID, 'oauth_registration', 'auth', 'info',
                    "New account created via {$provider}", ['provider' => $provider]);

                // 🔐 Complete login — B-055: see the matching guard above; the
                // account row now exists, but we must not tell the browser it's
                // logged in unless a real session was actually created.
                if (!Auth::loginOAuth($userID, false)) {
                    throw new RuntimeException('Failed to complete login session for user account.');
                }

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

    // 👤 User-friendly error message (don't expose internal details to users)
    // Detailed error is already logged above via error_log() and ErrorLogger
    $error = 'Authentication failed. Please try again or use a different sign-in method.';

    // 🔙 Redirect to login with error
    redirect('/login?oauth_error=' . urlencode($error));
}

// 🔙 Fallback redirect (should not reach here)
redirect('/login');
