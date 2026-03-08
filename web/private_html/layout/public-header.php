<?php
/**
 * ============================================================================
 * 🎨 SIGNula Public Header Component
 * ============================================================================
 * Reusable navigation header for SIGNula.com marketing pages
 *
 * Expected Variables:
 * - $pageTitle (string): Page title for SEO
 * - $metaDescription (string): Meta description
 * - $currentPage (string): Current page identifier for active state highlighting
 * - $ogImage (string, optional): Open Graph image URL
 *
 * @package    SIGNula
 * @subpackage Components
 * @version    1.0.0
 * ============================================================================
 */

// Set defaults if not provided
$pageTitle = $pageTitle ?? 'SIGNula - Universal Authentication System';
$metaDescription = $metaDescription ?? 'SIGNula provides secure, universal single sign-on authentication for web and mobile applications.';
$currentPage = $currentPage ?? '';
$ogImage = $ogImage ?? '/assets/images/og-image.png';

// Check if user is authenticated
$isAuthenticated = false;
$user = null;

// Only check auth if Auth class is loaded (shared with SIGNula.id)
if (class_exists('Auth')) {
    $isAuthenticated = Auth::isAuthenticated();
    if ($isAuthenticated) {
        $user = Auth::getCurrentUser();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <!-- SEO Meta Tags -->
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="keywords" content="authentication, single sign-on, SSO, OAuth, MFA, WebAuthn, PassKeys, security">
    <meta name="author" content="MWBMPartners">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage); ?>">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?>">
    <meta property="twitter:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
    <meta property="twitter:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta property="twitter:image" content="<?php echo htmlspecialchars($ogImage); ?>">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="alternate icon" href="/assets/images/favicon.ico">

    <!-- 📱 PWA Support -->
    <!-- @see https://web.dev/add-manifest/ — Web App Manifest -->
    <!-- @see https://developer.mozilla.org/en-US/docs/Web/Manifest — MDN Manifest Reference -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#007bff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="SIGNula">
    <link rel="apple-touch-icon" href="/assets/images/icons/icon-192x192.png">

    <!-- Bootstrap 5.3.2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- Font Awesome 6.4.2 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">

    <!-- 🛡️ CAPTCHA Scripts (if enabled and class available) -->
    <?php if (class_exists('CaptchaVerifier')) { echo CaptchaVerifier::getRequiredScripts(); } ?>
</head>
<body class="public-layout">

    <!-- Public Navigation Header -->
    <header class="public-header sticky-top">
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container">
                <!-- Brand/Logo -->
                <a class="navbar-brand d-flex align-items-center" href="/">
                    <i class="fas fa-shield-alt me-2 text-primary" style="font-size: 1.5rem;"></i>
                    <strong class="brand-text">SIGNula</strong>
                </a>

                <!-- Mobile Menu Toggle -->
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                        data-bs-target="#publicNavbar" aria-controls="publicNavbar"
                        aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <!-- Navigation Links -->
                <div class="collapse navbar-collapse" id="publicNavbar">
                    <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($currentPage === 'home') ? 'active' : ''; ?>"
                               href="/">Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($currentPage === 'features') ? 'active' : ''; ?>"
                               href="/features">Features</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($currentPage === 'pricing') ? 'active' : ''; ?>"
                               href="/pricing">Pricing</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle <?php echo (in_array($currentPage, ['docs', 'faq'])) ? 'active' : ''; ?>"
                               href="#" id="docsDropdown" role="button" data-bs-toggle="dropdown"
                               aria-expanded="false">
                                Documentation
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="docsDropdown">
                                <li><a class="dropdown-item" href="/docs">
                                    <i class="fas fa-book me-2"></i>Overview</a></li>
                                <li><a class="dropdown-item" href="/docs/getting-started">
                                    <i class="fas fa-rocket me-2"></i>Getting Started</a></li>
                                <li><a class="dropdown-item" href="/docs/api-reference">
                                    <i class="fas fa-code me-2"></i>API Reference</a></li>
                                <li><a class="dropdown-item" href="/docs/integration-guide">
                                    <i class="fas fa-plug me-2"></i>Integration Guide</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="/docs/faq">
                                    <i class="fas fa-question-circle me-2"></i>FAQ</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($currentPage === 'about') ? 'active' : ''; ?>"
                               href="/about">About</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($currentPage === 'contact') ? 'active' : ''; ?>"
                               href="/contact">Contact</a>
                        </li>
                    </ul>

                    <!-- User Menu / Auth Buttons -->
                    <div class="d-flex align-items-center">
                        <?php if ($isAuthenticated && $user): ?>
                            <!-- Authenticated User Menu -->
                            <div class="dropdown">
                                <button class="btn btn-link dropdown-toggle d-flex align-items-center text-decoration-none"
                                        type="button" id="userDropdown" data-bs-toggle="dropdown"
                                        aria-expanded="false">
                                    <?php
                                        // 🖼️ Resolve avatar URL with priority chain
                                        // @see web/private_html/utils/AvatarService.php
                                        $headerAvatarUrl = class_exists('AvatarService')
                                            ? AvatarService::resolve((int)$user['userID'], null, 48)
                                            : '';
                                        $headerDisplayName = htmlspecialchars($user['displayName'] ?? $user['username'] ?? 'User');
                                        $headerInitial = htmlspecialchars(mb_strtoupper(mb_substr($user['displayName'] ?? $user['username'] ?? 'U', 0, 1)));
                                    ?>
                                    <?php if (!empty($headerAvatarUrl)): ?>
                                        <!-- 🖼️ Avatar image with initials fallback on load error -->
                                        <img
                                            src="<?php echo htmlspecialchars($headerAvatarUrl); ?>"
                                            alt="<?php echo $headerDisplayName; ?>"
                                            class="rounded-circle me-2"
                                            style="width: 32px; height: 32px; object-fit: cover;"
                                            loading="lazy"
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
                                        >
                                        <span
                                            class="rounded-circle me-2 d-none align-items-center justify-content-center bg-primary text-white"
                                            style="width: 32px; height: 32px; font-size: 0.875rem; font-weight: 600;"
                                            aria-hidden="true"
                                        ><?php echo $headerInitial; ?></span>
                                    <?php else: ?>
                                        <!-- 🔤 CSS initials fallback (no avatar available) -->
                                        <span
                                            class="rounded-circle me-2 d-inline-flex align-items-center justify-content-center bg-primary text-white"
                                            style="width: 32px; height: 32px; font-size: 0.875rem; font-weight: 600;"
                                            role="img"
                                            aria-label="Avatar for <?php echo $headerDisplayName; ?>"
                                        ><?php echo $headerInitial; ?></span>
                                    <?php endif; ?>
                                    <span class="d-none d-md-inline">
                                        <?php echo $headerDisplayName; ?>
                                    </span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                    <li>
                                        <a class="dropdown-item" href="https://signula.id/dashboard">
                                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="https://signula.id/settings">
                                            <i class="fas fa-cog me-2"></i>Settings
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item text-danger" href="https://signula.id/logout">
                                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <!-- Guest User Buttons -->
                            <a href="https://signula.id/login" class="btn btn-outline-primary me-2">
                                <i class="fas fa-sign-in-alt me-1"></i>Login
                            </a>
                            <a href="https://signula.id/register" class="btn btn-primary">
                                <i class="fas fa-user-plus me-1"></i>Get Started
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Content Wrapper -->
    <main class="public-content">
