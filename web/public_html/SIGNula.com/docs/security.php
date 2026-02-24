<?php
$pageTitle = 'Security Best Practices - SIGNula Documentation';
$metaDescription = 'Security guidelines and best practices for implementing SIGNula authentication.';
$currentPage = 'docs';
$currentDoc = 'security';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">Security Best Practices</h1>
                <p class="lead">Guidelines for implementing secure authentication with SIGNula.</p>
                
                <h2 class="h3 mt-5 mb-3">API Key Security</h2>
                <div class="alert alert-warning">
                    <strong>Never expose API keys in client-side code!</strong>
                </div>
                <ul>
                    <li>Store API keys in environment variables</li>
                    <li>Use different keys for dev/staging/production</li>
                    <li>Rotate keys regularly</li>
                    <li>Revoke compromised keys immediately</li>
                </ul>
                
                <h2 class="h3 mt-5 mb-3">HTTPS/TLS</h2>
                <p>Always use HTTPS for all API requests. SIGNula enforces HTTPS in production.</p>
                <pre class="bg-dark text-white p-3 rounded"><code>// Good
https://signula.id/api/v1/auth/login

// Bad - will be rejected
http://signula.id/api/v1/auth/login</code></pre>
                
                <h2 class="h3 mt-5 mb-3" id="passkeys">PassKeys & Biometrics</h2>
                <p>For maximum security, enable PassKeys/WebAuthn:</p>
                <ul>
                    <li>Biometric data never leaves the device</li>
                    <li>Resistant to phishing attacks</li>
                    <li>No passwords to steal</li>
                    <li>Hardware-backed security</li>
                </ul>
                
                <h2 class="h3 mt-5 mb-3">Rate Limiting</h2>
                <p>Our API includes built-in rate limiting:</p>
                <ul>
                    <li><strong>Free tier:</strong> 100 requests/hour</li>
                    <li><strong>Basic tier:</strong> 1,000 requests/hour</li>
                    <li><strong>Premium tier:</strong> 10,000 requests/hour</li>
                    <li><strong>Enterprise:</strong> Custom limits</li>
                </ul>
                
                <h2 class="h3 mt-5 mb-3">Security Headers</h2>
                <p>Implement these security headers in your application:</p>
                <pre class="bg-dark text-white p-3 rounded"><code>X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000</code></pre>
                
                <div class="alert alert-success mt-5">
                    <strong>Security Questions?</strong> Contact our security team at <a href="mailto:security@signula.com" class="alert-link">security@signula.com</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
