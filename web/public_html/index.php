<?php
/**
 * ============================================================================
 * 🏠 SIGNula - Main Entry Point
 * ============================================================================
 *
 * Purpose: Main landing page and request router
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔍 Check if user is authenticated
$isAuthenticated = Auth::isAuthenticated();
$currentUser = null;

if ($isAuthenticated) {
    $currentUser = Auth::getCurrentUser();
}

// 📄 Page title and meta
$pageTitle = 'SIGNula - Universal Login System';
$pageDescription = 'Secure, universal single sign-on authentication for all your applications';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <meta name="author" content="MWBMPartners">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- 🎨 Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="alternate icon" href="/assets/images/favicon.ico">

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- 🎨 Custom CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">

    <style>
        /* 🎨 Inline critical CSS */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .hero-section {
            min-height: 80vh;
            display: flex;
            align-items: center;
            color: white;
        }

        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin: 15px 0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .feature-icon {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 20px;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            transition: transform 0.3s ease;
        }

        .btn-primary-custom:hover {
            transform: scale(1.05);
        }

        .navbar-custom {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body>

<!-- 🧭 Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
    <div class="container">
        <a class="navbar-brand" href="/">
            <i class="fas fa-shield-alt"></i> <strong>SIGNula</strong>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if ($isAuthenticated && $currentUser): ?>
                    <!-- 👤 Authenticated User Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="/dashboard">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($currentUser['displayName'] ?? $currentUser['username']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/account"><i class="fas fa-cog"></i> Account Settings</a></li>
                            <li><a class="dropdown-item" href="/security"><i class="fas fa-lock"></i> Security</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <!-- 🔓 Guest Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="/login">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/register">
                            <i class="fas fa-user-plus"></i> Register
                        </a>
                    </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="https://SIGNula.com" target="_blank">
                        <i class="fas fa-external-link-alt"></i> About
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- 🎯 Hero Section -->
<div class="hero-section">
    <div class="container text-center">
        <h1 class="display-3 fw-bold mb-4">
            <i class="fas fa-shield-alt"></i> SIGNula
        </h1>
        <h2 class="h3 mb-4">Universal Login System</h2>
        <p class="lead mb-5">
            One account. Unlimited possibilities.<br>
            Secure, seamless authentication across all your applications.
        </p>
        <?php if (!$isAuthenticated): ?>
            <div class="d-flex gap-3 justify-content-center">
                <a href="/register" class="btn btn-light btn-lg px-5">
                    <i class="fas fa-user-plus"></i> Get Started Free
                </a>
                <a href="/login" class="btn btn-outline-light btn-lg px-5">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </a>
            </div>
        <?php else: ?>
            <div>
                <a href="/dashboard" class="btn btn-light btn-lg px-5">
                    <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ✨ Features Section -->
<div class="container py-5">
    <div class="row">
        <!-- 🔐 Security Feature -->
        <div class="col-md-4">
            <div class="feature-card text-center">
                <div class="feature-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h3>Enterprise Security</h3>
                <p>Military-grade AES-256 encryption, multi-factor authentication, and continuous security monitoring to keep your data safe.</p>
            </div>
        </div>

        <!-- 🔗 Integration Feature -->
        <div class="col-md-4">
            <div class="feature-card text-center">
                <div class="feature-icon">
                    <i class="fas fa-link"></i>
                </div>
                <h3>Universal Integration</h3>
                <p>Link your Google, Microsoft, Apple, and other accounts for seamless single sign-on across all platforms.</p>
            </div>
        </div>

        <!-- 🚀 Performance Feature -->
        <div class="col-md-4">
            <div class="feature-card text-center">
                <div class="feature-icon">
                    <i class="fas fa-rocket"></i>
                </div>
                <h3>Lightning Fast</h3>
                <p>Optimized infrastructure ensures instant authentication and minimal latency for the best user experience.</p>
            </div>
        </div>

        <!-- 📱 Mobile Feature -->
        <div class="col-md-4">
            <div class="feature-card text-center">
                <div class="feature-icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h3>Mobile First</h3>
                <p>Fully responsive design with PWA support. Access your account seamlessly from any device, anywhere.</p>
            </div>
        </div>

        <!-- 🔑 Passwordless Feature -->
        <div class="col-md-4">
            <div class="feature-card text-center">
                <div class="feature-icon">
                    <i class="fas fa-fingerprint"></i>
                </div>
                <h3>Passwordless Login</h3>
                <p>Use biometrics, magic links, or authenticator apps for secure, convenient passwordless authentication.</p>
            </div>
        </div>

        <!-- 🛡️ Compliance Feature -->
        <div class="col-md-4">
            <div class="feature-card text-center">
                <div class="feature-icon">
                    <i class="fas fa-certificate"></i>
                </div>
                <h3>GDPR Compliant</h3>
                <p>Full compliance with GDPR, CCPA, and international data protection regulations.</p>
            </div>
        </div>
    </div>
</div>

<!-- 📊 Stats Section -->
<div class="container py-5 text-white text-center">
    <div class="row">
        <div class="col-md-3">
            <h2 class="display-4 fw-bold"><i class="fas fa-users"></i></h2>
            <h3>0+</h3>
            <p>Active Users</p>
        </div>
        <div class="col-md-3">
            <h2 class="display-4 fw-bold"><i class="fas fa-server"></i></h2>
            <h3>0+</h3>
            <p>Integrated Apps</p>
        </div>
        <div class="col-md-3">
            <h2 class="display-4 fw-bold"><i class="fas fa-shield-alt"></i></h2>
            <h3>100%</h3>
            <p>Secure</p>
        </div>
        <div class="col-md-3">
            <h2 class="display-4 fw-bold"><i class="fas fa-globe"></i></h2>
            <h3>24/7</h3>
            <p>Available</p>
        </div>
    </div>
</div>

<!-- 📧 Footer -->
<footer class="bg-dark text-white py-4 mt-5">
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <h5><i class="fas fa-shield-alt"></i> SIGNula</h5>
                <p class="text-muted">Universal Login System</p>
                <p class="small">&copy; <?php echo date('Y'); ?> MWBMPartners. All rights reserved.</p>
            </div>
            <div class="col-md-3">
                <h6>Quick Links</h6>
                <ul class="list-unstyled">
                    <li><a href="https://SIGNula.com" class="text-white-50">About Us</a></li>
                    <li><a href="/privacy" class="text-white-50">Privacy Policy</a></li>
                    <li><a href="/terms" class="text-white-50">Terms of Service</a></li>
                    <li><a href="/contact" class="text-white-50">Contact</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6>Connect</h6>
                <ul class="list-unstyled">
                    <li><a href="#" class="text-white-50"><i class="fab fa-twitter"></i> Twitter</a></li>
                    <li><a href="#" class="text-white-50"><i class="fab fa-github"></i> GitHub</a></li>
                    <li><a href="#" class="text-white-50"><i class="fab fa-linkedin"></i> LinkedIn</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<!-- 🔍 Debug Information (only in debug mode) -->
<?php if (DEBUG_MODE && isset($_GET['debug'])): ?>
<div class="container mt-3">
    <div class="card bg-dark text-white">
        <div class="card-header">
            <i class="fas fa-bug"></i> Debug Information
        </div>
        <div class="card-body">
            <pre><?php
                echo "Authenticated: " . ($isAuthenticated ? 'Yes' : 'No') . "\n";
                echo "PHP Version: " . phpversion() . "\n";
                echo "Environment: " . ENVIRONMENT . "\n";
                echo "Database Connected: " . (Database::isConnected() ? 'Yes' : 'No') . "\n";
                if ($currentUser) {
                    echo "\nCurrent User:\n";
                    print_r($currentUser);
                }
            ?></pre>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
