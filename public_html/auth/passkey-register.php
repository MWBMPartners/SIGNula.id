<?php
/**
 * ============================================================================
 * 🔐 SIGNula - PassKey Registration
 * ============================================================================
 *
 * Purpose: Register a new PassKey/WebAuthn credential
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login
requireLogin();

$pageTitle = 'Add PassKey';
$user = getCurrentUser();

// 🎨 Include header
include SIGNULA_ROOT . '/private_html/layout/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <div class="display-1 mb-3">🔐</div>
                        <h1 class="h3">Add a PassKey</h1>
                        <p class="text-muted">
                            Use your device's biometric authentication to log in securely without a password
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
                        Your browser or device doesn't support PassKeys. Please try:
                        <ul class="mb-0 mt-2">
                            <li>Using a modern browser (Chrome, Safari, Edge, Firefox)</li>
                            <li>Updating your browser to the latest version</li>
                            <li>Using a device with biometric capabilities</li>
                        </ul>
                    </div>

                    <!-- Registration Form -->
                    <div id="registrationForm" style="display: none;">
                        <div class="mb-4">
                            <label for="deviceName" class="form-label">Device Name (Optional)</label>
                            <input
                                type="text"
                                class="form-control"
                                id="deviceName"
                                placeholder="e.g., My iPhone, Work Laptop"
                            >
                            <div class="form-text">
                                Give this PassKey a friendly name to help you identify it later
                            </div>
                        </div>

                        <div class="alert alert-info small">
                            <strong>ℹ️ What will happen:</strong>
                            <ol class="mb-0 mt-2 ps-3">
                                <li>Your device will prompt you to authenticate (fingerprint, face, or PIN)</li>
                                <li>A secure PassKey will be created and stored on your device</li>
                                <li>You'll be able to log in quickly using this PassKey</li>
                            </ol>
                        </div>

                        <button id="registerButton" class="btn btn-primary btn-lg w-100" onclick="registerPassKey()">
                            <span id="registerButtonText">🔐 Create PassKey</span>
                            <span id="registerButtonSpinner" class="spinner-border spinner-border-sm ms-2" style="display: none;" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </span>
                        </button>
                    </div>

                    <!-- Status Messages -->
                    <div id="statusMessage" class="mt-3" style="display: none;"></div>

                    <hr class="my-4">

                    <div class="text-center">
                        <a href="/settings/security" class="btn btn-outline-secondary">
                            ← Back to Security Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * 🔐 PassKey Registration Script
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
    const registrationForm = document.getElementById('registrationForm');

    compatCheck.style.display = 'block';

    // Check if WebAuthn is supported
    if (!window.PublicKeyCredential) {
        compatCheck.style.display = 'none';
        notSupported.style.display = 'block';
        return;
    }

    // Check if platform authenticator is available
    PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
        .then(available => {
            compatCheck.style.display = 'none';

            if (available) {
                registrationForm.style.display = 'block';
            } else {
                // Still allow registration with cross-platform authenticators
                registrationForm.style.display = 'block';
                showStatus('info', 'Platform authenticator not detected. You can still use a security key.');
            }
        })
        .catch(err => {
            console.error('Compatibility check failed:', err);
            compatCheck.style.display = 'none';
            notSupported.style.display = 'block';
        });
}

/**
 * 🔐 Register PassKey
 */
async function registerPassKey() {
    const button = document.getElementById('registerButton');
    const buttonText = document.getElementById('registerButtonText');
    const buttonSpinner = document.getElementById('registerButtonSpinner');
    const deviceName = document.getElementById('deviceName').value.trim();

    try {
        // Disable button
        button.disabled = true;
        buttonText.textContent = 'Creating PassKey...';
        buttonSpinner.style.display = 'inline-block';

        // Step 1: Get registration options from server
        showStatus('info', '📡 Contacting server...');

        const optionsResponse = await fetch('/api/webauthn/register-options.php', {
            method: 'GET',
            credentials: 'same-origin'
        });

        if (!optionsResponse.ok) {
            throw new Error('Failed to get registration options');
        }

        const optionsData = await optionsResponse.json();

        if (!optionsData.success) {
            throw new Error(optionsData.error || 'Failed to get registration options');
        }

        const options = optionsData.options;

        // Step 2: Convert challenge and user ID from base64
        const publicKeyCredentialCreationOptions = {
            ...options,
            challenge: base64ToArrayBuffer(options.challenge),
            user: {
                ...options.user,
                id: base64ToArrayBuffer(options.user.id)
            },
            excludeCredentials: options.excludeCredentials?.map(cred => ({
                ...cred,
                id: base64ToArrayBuffer(cred.id)
            })) || []
        };

        // Step 3: Create credential
        showStatus('info', '🔐 Authenticating with your device...');

        const credential = await navigator.credentials.create({
            publicKey: publicKeyCredentialCreationOptions
        });

        if (!credential) {
            throw new Error('Failed to create credential');
        }

        // Step 4: Prepare credential data for server
        const credentialData = {
            id: credential.id,
            rawId: arrayBufferToBase64(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                attestationObject: arrayBufferToBase64(credential.response.attestationObject)
            }
        };

        // Step 5: Send to server for verification
        showStatus('info', '✅ Verifying credential...');

        const verifyResponse = await fetch('/api/webauthn/register-verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                credential: credentialData,
                deviceName: deviceName || null
            })
        });

        const verifyData = await verifyResponse.json();

        if (!verifyData.success) {
            throw new Error(verifyData.error || 'Verification failed');
        }

        // Success!
        showStatus('success', '✅ PassKey created successfully! Redirecting...');

        setTimeout(() => {
            window.location.href = '/settings/security';
        }, 2000);

    } catch (error) {
        console.error('PassKey registration error:', error);

        let errorMessage = 'Failed to create PassKey. ';

        if (error.name === 'NotAllowedError') {
            errorMessage += 'Authentication was cancelled or timed out.';
        } else if (error.name === 'InvalidStateError') {
            errorMessage += 'This authenticator is already registered.';
        } else {
            errorMessage += error.message || 'Please try again.';
        }

        showStatus('danger', errorMessage);

        // Re-enable button
        button.disabled = false;
        buttonText.textContent = '🔐 Create PassKey';
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
</script>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/private_html/layout/footer.php';
?>
