<?php
/**
 * ============================================================================
 * 🔐 SIGNula - PassKey Login
 * ============================================================================
 *
 * Purpose: Authenticate using PassKey/WebAuthn
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔄 If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: /dashboard');
    exit;
}

$pageTitle = 'PassKey Login';

// 🎨 Include header
include SIGNULA_ROOT . '/_includes/layout/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <div class="display-1 mb-3">🔐</div>
                        <h1 class="h3">PassKey Login</h1>
                        <p class="text-muted">
                            Use your device's biometric authentication to log in securely
                        </p>
                    </div>

                    <!-- Browser Compatibility Check -->
                    <div id="compatibilityCheck" class="alert alert-info" style="display: none;">
                        <div class="d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm me-2" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            Checking device compatibility...
                        </div>
                    </div>

                    <div id="notSupported" class="alert alert-warning" style="display: none;">
                        <strong>⚠️ Not Supported</strong><br>
                        Your browser or device doesn't support PassKeys.
                        <div class="mt-3">
                            <a href="/auth/login" class="btn btn-primary btn-sm">
                                Use Traditional Login
                            </a>
                        </div>
                    </div>

                    <!-- Login Options -->
                    <div id="loginOptions" style="display: none;">
                        <!-- Option 1: Login with known email -->
                        <div class="mb-4">
                            <label for="email" class="form-label">Email Address (Optional)</label>
                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                placeholder="you@example.com"
                                autocomplete="email webauthn"
                            >
                            <div class="form-text">
                                Enter your email to use a specific PassKey, or leave blank for device-based authentication
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button id="loginButton" class="btn btn-primary btn-lg" onclick="loginWithPassKey()">
                                <span id="loginButtonText">🔐 Login with PassKey</span>
                                <span id="loginButtonSpinner" class="spinner-border spinner-border-sm ms-2" style="display: none;" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </span>
                            </button>
                        </div>

                        <div class="alert alert-info small mt-3">
                            <strong>ℹ️ What will happen:</strong>
                            <ol class="mb-0 mt-2 ps-3">
                                <li>Your device will prompt you to authenticate</li>
                                <li>Use your fingerprint, face, or device PIN</li>
                                <li>You'll be logged in securely without typing a password</li>
                            </ol>
                        </div>
                    </div>

                    <!-- Status Messages -->
                    <div id="statusMessage" class="mt-3" style="display: none;"></div>

                    <hr class="my-4">

                    <div class="text-center">
                        <p class="text-muted small mb-2">Don't have a PassKey?</p>
                        <div class="d-grid gap-2">
                            <a href="/auth/login" class="btn btn-outline-secondary btn-sm">
                                🔑 Traditional Login
                            </a>
                            <a href="/auth/passwordless-request" class="btn btn-outline-secondary btn-sm">
                                📧 Email Login Link
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-3">
                <p class="text-muted small">
                    Don't have an account?
                    <a href="/auth/register">Create one now</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * 🔐 PassKey Authentication Script
 */

// Check browser compatibility on page load
document.addEventListener('DOMContentLoaded', function() {
    checkCompatibility();
});

/**
 * ✅ Check Browser Compatibility
 */
function checkCompatibility() {
    const compatCheck = document.getElementById('compatibilityCheck');
    const notSupported = document.getElementById('notSupported');
    const loginOptions = document.getElementById('loginOptions');

    compatCheck.style.display = 'block';

    // Check if WebAuthn is supported
    if (!window.PublicKeyCredential) {
        compatCheck.style.display = 'none';
        notSupported.style.display = 'block';
        return;
    }

    // WebAuthn is supported
    compatCheck.style.display = 'none';
    loginOptions.style.display = 'block';
}

/**
 * 🔐 Login with PassKey
 */
async function loginWithPassKey() {
    const button = document.getElementById('loginButton');
    const buttonText = document.getElementById('loginButtonText');
    const buttonSpinner = document.getElementById('loginButtonSpinner');
    const email = document.getElementById('email').value.trim() || null;

    try {
        // Disable button
        button.disabled = true;
        buttonText.textContent = 'Authenticating...';
        buttonSpinner.style.display = 'inline-block';

        // Step 1: Get authentication options from server
        showStatus('info', '📡 Contacting server...');

        const optionsResponse = await fetch('/api/webauthn/auth-options.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ email })
        });

        if (!optionsResponse.ok) {
            throw new Error('Failed to get authentication options');
        }

        const optionsData = await optionsResponse.json();

        if (!optionsData.success) {
            throw new Error(optionsData.error || 'Failed to get authentication options');
        }

        const options = optionsData.options;

        // Step 2: Convert challenge and credential IDs from base64
        const publicKeyCredentialRequestOptions = {
            ...options,
            challenge: base64ToArrayBuffer(options.challenge),
            allowCredentials: options.allowCredentials?.map(cred => ({
                ...cred,
                id: base64ToArrayBuffer(cred.id)
            })) || []
        };

        // Step 3: Get credential
        showStatus('info', '🔐 Authenticating with your device...');

        const assertion = await navigator.credentials.get({
            publicKey: publicKeyCredentialRequestOptions
        });

        if (!assertion) {
            throw new Error('Failed to get credential');
        }

        // Step 4: Prepare assertion data for server
        const assertionData = {
            id: assertion.id,
            rawId: arrayBufferToBase64(assertion.rawId),
            type: assertion.type,
            response: {
                clientDataJSON: arrayBufferToBase64(assertion.response.clientDataJSON),
                authenticatorData: arrayBufferToBase64(assertion.response.authenticatorData),
                signature: arrayBufferToBase64(assertion.response.signature),
                userHandle: assertion.response.userHandle ? arrayBufferToBase64(assertion.response.userHandle) : null
            }
        };

        // Step 5: Send to server for verification
        showStatus('info', '✅ Verifying authentication...');

        const verifyResponse = await fetch('/api/webauthn/auth-verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                credential: assertionData
            })
        });

        const verifyData = await verifyResponse.json();

        if (!verifyData.success) {
            throw new Error(verifyData.error || 'Verification failed');
        }

        // Success!
        showStatus('success', '✅ Login successful! Redirecting...');

        setTimeout(() => {
            window.location.href = verifyData.redirectUrl || '/dashboard';
        }, 1500);

    } catch (error) {
        console.error('PassKey authentication error:', error);

        let errorMessage = 'Authentication failed. ';

        if (error.name === 'NotAllowedError') {
            errorMessage += 'Authentication was cancelled or timed out.';
        } else if (error.name === 'InvalidStateError') {
            errorMessage += 'No matching credentials found.';
        } else {
            errorMessage += error.message || 'Please try again.';
        }

        showStatus('danger', errorMessage);

        // Re-enable button
        button.disabled = false;
        buttonText.textContent = '🔐 Login with PassKey';
        buttonSpinner.style.display = 'none';
    }
}

/**
 * 📊 Show Status Message
 */
function showStatus(type, message) {
    const statusDiv = document.getElementById('statusMessage');
    statusDiv.className = `alert alert-${type}`;
    statusDiv.innerHTML = message;
    statusDiv.style.display = 'block';
}

/**
 * 🔄 Base64 to ArrayBuffer
 */
function base64ToArrayBuffer(base64) {
    const binaryString = atob(base64.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
    }
    return bytes.buffer;
}

/**
 * 🔄 ArrayBuffer to Base64
 */
function arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
}

// Allow Enter key to trigger login
document.getElementById('email')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        loginWithPassKey();
    }
});
</script>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/_includes/layout/footer.php';
?>
