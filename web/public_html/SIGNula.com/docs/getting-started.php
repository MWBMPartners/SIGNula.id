<?php
$pageTitle = 'Getting Started - SIGNula Documentation';
$metaDescription = 'Quick start guide for integrating SIGNula universal authentication into your application.';
$currentPage = 'docs';
$currentDoc = 'getting-started';
require_once __DIR__ . '/../../../private_html/layout/public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once __DIR__ . '/../../../private_html/layout/docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">Getting Started</h1>
                <p class="lead">Get up and running with SIGNula in minutes.</p>
                
                <h2 class="h3 mt-5 mb-3">1. Create an Account</h2>
                <p>Sign up for a free SIGNula account:</p>
                <pre class="bg-dark text-white p-3 rounded"><code>Visit: https://signula.id/register
Fill in your details and verify your email</code></pre>
                
                <h2 class="h3 mt-5 mb-3">2. Get API Credentials</h2>
                <ol>
                    <li>Log in to your account</li>
                    <li>Navigate to Settings → API</li>
                    <li>Click "Generate API Key"</li>
                    <li>Save your API key securely</li>
                </ol>
                
                <h2 class="h3 mt-5 mb-3">3. Make Your First API Call</h2>
                <pre class="bg-dark text-white p-3 rounded"><code>curl -X POST https://signula.id/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_api_key_here" \
  -d '{
    "email": "user@example.com",
    "password": "password"
  }'</code></pre>
                
                <h2 class="h3 mt-5 mb-3">Next Steps</h2>
                <ul>
                    <li><a href="/docs/api-reference">View complete API Reference</a></li>
                    <li><a href="/docs/integration-guide">Integration Guide for your framework</a></li>
                    <li><a href="/docs/security">Security Best Practices</a></li>
                </ul>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../../private_html/layout/public-footer.php'; ?>
