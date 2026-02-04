<?php
$pageTitle = 'API Reference - SIGNula Documentation';
$metaDescription = 'Complete API reference for SIGNula authentication system with endpoints, parameters, and examples.';
$currentPage = 'docs';
$currentDoc = 'api-reference';
require_once __DIR__ . '/../../../private_html/layout/public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once __DIR__ . '/../../../private_html/layout/docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">API Reference</h1>
                <p class="lead">Complete REST API documentation with 30+ endpoints.</p>
                
                <div class="alert alert-info">
                    <strong>Base URL:</strong> <code>https://signula.id/api/v1</code>
                </div>
                
                <h2 class="h3 mt-5 mb-3" id="authentication">Authentication Endpoints</h2>
                <div class="card mb-3">
                    <div class="card-header bg-success text-white">
                        <code>POST /api/v1/auth/login</code>
                    </div>
                    <div class="card-body">
                        <p><strong>Description:</strong> Authenticate user with email and password</p>
                        <p><strong>Request Body:</strong></p>
                        <pre class="bg-dark text-white p-3 rounded"><code>{
  "email": "user@example.com",
  "password": "secure_password"
}</code></pre>
                        <p><strong>Response:</strong></p>
                        <pre class="bg-dark text-white p-3 rounded"><code>{
  "success": true,
  "data": {
    "user_id": 123,
    "session_token": "abc123...",
    "requires_mfa": false
  }
}</code></pre>
                    </div>
                </div>
                
                <h2 class="h3 mt-5 mb-3" id="user-management">User Management</h2>
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <code>GET /api/v1/user/profile</code>
                    </div>
                    <div class="card-body">
                        <p><strong>Description:</strong> Get current user profile</p>
                        <p><strong>Headers:</strong> <code>Authorization: Bearer {token}</code></p>
                    </div>
                </div>
                
                <h2 class="h3 mt-5 mb-3" id="mfa">MFA Endpoints</h2>
                <p>Manage multi-factor authentication settings and verification.</p>
                <ul>
                    <li><code>POST /api/v1/mfa/enable</code> - Enable MFA</li>
                    <li><code>POST /api/v1/mfa/verify</code> - Verify MFA code</li>
                    <li><code>GET /api/v1/mfa/backup-codes</code> - Get backup codes</li>
                </ul>
                
                <h2 class="h3 mt-5 mb-3" id="oauth">OAuth Endpoints</h2>
                <p>Link and manage OAuth provider accounts.</p>
                <ul>
                    <li><code>GET /api/v1/oauth/linked</code> - Get linked accounts</li>
                    <li><code>POST /api/v1/oauth/link</code> - Link OAuth account</li>
                    <li><code>DELETE /api/v1/oauth/unlink/{provider}</code> - Unlink account</li>
                </ul>
                
                <div class="alert alert-success mt-5">
                    <strong>Need more details?</strong> View complete API documentation at <a href="https://signula.id/api/docs" class="alert-link">signula.id/api/docs</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../../private_html/layout/public-footer.php'; ?>
