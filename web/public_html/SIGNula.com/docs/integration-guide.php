<?php
$pageTitle = 'Integration Guide - SIGNula Documentation';
$metaDescription = 'Step-by-step integration guides for popular frameworks and platforms.';
$currentPage = 'docs';
$currentDoc = 'integration-guide';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">Integration Guide</h1>
                <p class="lead">Integrate SIGNula with your favorite frameworks and platforms.</p>
                
                <h2 class="h3 mt-5 mb-3">Web Frameworks</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5><i class="fab fa-react me-2 text-primary"></i>React</h5>
                                <p class="small">Install via npm, configure context provider, use hooks</p>
                                <code>npm install @signula/react</code>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5><i class="fab fa-vuejs me-2 text-success"></i>Vue.js</h5>
                                <p class="small">Install plugin, add to Vue instance, use composables</p>
                                <code>npm install @signula/vue</code>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5><i class="fab fa-angular me-2 text-danger"></i>Angular</h5>
                                <p class="small">Install module, import in app, use service</p>
                                <code>npm install @signula/angular</code>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5><i class="fab fa-node-js me-2 text-success"></i>Node.js</h5>
                                <p class="small">Install SDK, initialize client, make requests</p>
                                <code>npm install @signula/node</code>
                            </div>
                        </div>
                    </div>
                </div>
                
                <h2 class="h3 mt-5 mb-3">Mobile Platforms</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5><i class="fab fa-apple me-2"></i>iOS (Swift)</h5>
                                <p class="small">Install via CocoaPods or SPM</p>
                                <code>pod 'SIGNula'</code>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5><i class="fab fa-android me-2 text-success"></i>Android (Kotlin)</h5>
                                <p class="small">Install via Gradle</p>
                                <code>implementation 'com.signula:android'</code>
                            </div>
                        </div>
                    </div>
                </div>
                
                <h2 class="h3 mt-5 mb-3">Example Integration</h2>
                <pre class="bg-dark text-white p-3 rounded"><code>import { SIGNulaClient } from '@signula/sdk';

const client = new SIGNulaClient({
  apiKey: 'your_api_key_here'
});

// Login
const result = await client.auth.login({
  email: 'user@example.com',
  password: 'password'
});

console.log('Logged in:', result.user);</code></pre>
                
                <div class="alert alert-info mt-4">
                    <strong>Need help?</strong> Contact our integration team at <a href="mailto:integrations@signula.com">integrations@signula.com</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
