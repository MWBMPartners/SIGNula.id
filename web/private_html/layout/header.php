<?php
/**
 * ============================================================================
 * 🎨 SIGNula - Shared Application Header (account / settings pages)
 * ============================================================================
 * Reusable page shell (DOCTYPE → open <body> + top nav) for the signed-in
 * "SIGNula.id" application pages — settings/*, and the pre-login auth/
 * passkey-*.php + auth/passwordless-*.php pages. Paired 1:1 with footer.php
 * in this same directory.
 *
 * 🐛 B-060 — this file (and footer.php) did not exist at all. 17 pages under
 * web/public_html/ (13 settings/*.php + 4 auth/passkey-*.php|passwordless-
 * *.php) `include SIGNULA_ROOT . '.../private_html/layout/header.php'`
 * unconditionally — every one of them fataled with "Failed opening required"
 * the moment SIGNULA_ROOT itself was defined (see web/_config/config.php).
 * This is Option A of that fix: create the missing shared layout ONCE so
 * every dependent page renders, instead of touching each page's own logic.
 *
 * ⚠️ NOT the same file as public-header.php (SIGNula.com marketing pages +
 * pricing/checkout) or admin-header.php (referenced by admin/email-webhooks.
 * php but — separately from this fix — ALSO does not exist; flagged, not
 * fixed here, see the B-060 report). Do not merge these layouts — they serve
 * different page families with different navigation needs.
 *
 * ----------------------------------------------------------------------------
 * 📥 Expected variables (ALL optional — every one defaults safely so this
 * file never fatals on a page that forgot to set one; see connected-apps.php
 * for the ONE settings/* page that deliberately does NOT use this shell):
 *
 * - $pageTitle       (string) Rendered as "{$pageTitle} - SIGNula" in <title>.
 *                     Every real consumer sets this (e.g. 'Profile Settings').
 * - $pageDescription (string) <meta name="description"> content.
 * - $bodyClass       (string) Extra space-separated class(es) on <body>, on
 *                     top of the base "app-shell" class this file always adds.
 *
 * ----------------------------------------------------------------------------
 * 🧱 Nesting contract (READ BEFORE editing either half of this pair):
 *
 * This file ends immediately after opening <body> and rendering the top nav
 * bar. It deliberately does NOT open a wrapping <div class="container">,
 * <main>, or any row/column grid — every consumer page opens its OWN
 * top-level container right after the include (verified against all 17
 * consumers — every single one starts its content with
 * `<div class="container mt-5">` or `<div class="container mt-5 mb-5">`)
 * and closes that same div itself, BEFORE including footer.php. Some of
 * those pages nest `private_html/layout/settings-sidebar.php` as a
 * `col-md-3` alongside a `col-md-9` content column inside their own
 * `.row` — that too is entirely the PAGE's own markup, not this file's.
 *
 * Because this file opens no wrapper, footer.php (its pair) closes none
 * either — footer.php only renders the site footer, JS includes, and the
 * closing </body></html>. This keeps the pairing balanced for BOTH page
 * shapes (settings pages with the sidebar column, and auth pages without
 * one) without this file needing to know which shape the calling page uses.
 *
 * @package    SIGNula
 * @subpackage Components
 * @version    1.0.0
 * @see        web/private_html/layout/footer.php          (its required pair)
 * @see        web/private_html/layout/settings-sidebar.php (nested by settings/* pages, NOT by this file)
 * @see        web/private_html/layout/public-header.php    (sibling for SIGNula.com/pricing/checkout — this file's avatar-dropdown markup mirrors its authenticated-user block)
 * @see        web/_config/config.php                       (defines SIGNULA_ROOT + requireLogin()/isLoggedIn()/getCurrentUser(), the other half of B-060)
 * ============================================================================
 */

// ----------------------------------------------------------------------------
// 🧩 Safe defaults — every consumer variable is OPTIONAL. A page that forgets
//    to set $pageTitle (etc.) must still render a valid page, never a notice
//    or a fatal.
// ----------------------------------------------------------------------------
$pageTitle       = $pageTitle ?? 'SIGNula';
$pageDescription = $pageDescription ?? 'Manage your SIGNula ID — your universal, secure single sign-on account.';
$bodyClass       = $bodyClass ?? '';

// ----------------------------------------------------------------------------
// 👤 Resolve the current session, if any.
//
// Mirrors public-header.php's guard exactly: only touch Auth if the class is
// actually loadable (it always should be — Auth.php is autoloadable via the
// spl_autoload_register() in config.php — but a shared layout partial must
// never assume a specific class graph is loaded, since auth/passwordless-
// *.php reach this file BEFORE any session necessarily exists).
// ----------------------------------------------------------------------------
$isAuthenticated = false;
$headerUser       = null;

if (class_exists('Auth')) {
    $isAuthenticated = Auth::isAuthenticated();

    if ($isAuthenticated) {
        $headerUser = Auth::getCurrentUser();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - SIGNula</title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <!-- 🔒 Account/settings pages are never search-indexed. -->
    <meta name="robots" content="noindex, nofollow">

    <!-- 🎨 Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="alternate icon" href="/assets/images/favicon.ico">

    <!-- 📱 PWA support — same manifest/meta as public-header.php so the "Add to Home Screen" identity stays consistent app-wide -->
    <!-- @see https://developer.mozilla.org/en-US/docs/Web/Manifest -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#007bff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="SIGNula">
    <link rel="apple-touch-icon" href="/assets/images/icons/icon-192x192.png">

    <!-- 🌙 Dark mode CSS + toggle (loaded early to avoid a flash of the wrong theme) -->
    <!-- @see https://web.dev/prefers-color-scheme/ -->
    <link rel="stylesheet" href="/assets/css/dark-mode.css">
    <script src="/assets/js/theme-toggle.js"></script>

    <!-- 🎨 Bootstrap 5.3.2 CSS (CDN, SRI-pinned — same version/hash used by every other page in this app) -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
        crossorigin="anonymous"
    >

    <!-- 🎨 Font Awesome 6.4.2 -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
        integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
        crossorigin="anonymous"
        referrerpolicy="no-referrer"
    >

    <!-- 🎨 Site CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body class="app-shell<?php echo $bodyClass !== '' ? ' ' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') : ''; ?>">

    <!-- ============================================================ -->
    <!-- 🧭 Top navigation — brand + theme toggle + logged-in identity -->
    <!-- ============================================================ -->
    <header class="app-header sticky-top bg-white border-bottom">
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container-fluid px-3 px-md-4">

                <!-- Brand -->
                <a class="navbar-brand d-flex align-items-center" href="/dashboard">
                    <i class="fas fa-shield-alt me-2 text-primary" style="font-size: 1.5rem;"></i>
                    <strong>SIGNula</strong>
                </a>

                <!-- 🌙 Theme toggle (progressive enhancement — theme-toggle.js wires this up; harmless no-op without JS) -->
                <button class="theme-toggle btn btn-sm btn-link ms-auto me-2" type="button" aria-label="Toggle theme">
                    <i class="fas fa-sun"></i>
                </button>

                <?php if ($isAuthenticated && $headerUser !== null): ?>

                    <!-- ============================================== -->
                    <!-- 👤 Signed-in identity — top-right per project brief: -->
                    <!--    avatar > display name > dropdown (Profile/Settings/Logout) -->
                    <!-- ============================================== -->
                    <div class="dropdown">
                        <button
                            class="btn btn-link dropdown-toggle d-flex align-items-center text-decoration-none"
                            type="button"
                            id="appUserDropdown"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                        >
                            <?php
                                // 🖼️ Avatar priority chain — @see web/private_html/utils/AvatarService.php
                                //    (partner upload → global upload → OAuth → fallback services → SVG initials)
                                $navAvatarUrl = class_exists('AvatarService')
                                    ? AvatarService::resolve((int) $headerUser['userID'], null, 40)
                                    : '';
                                $navDisplayName = htmlspecialchars(
                                    $headerUser['displayName'] ?? $headerUser['username'] ?? 'User',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                $navInitial = htmlspecialchars(
                                    mb_strtoupper(mb_substr($headerUser['displayName'] ?? $headerUser['username'] ?? 'U', 0, 1)),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?>
                            <?php if (!empty($navAvatarUrl)): ?>
                                <!-- 🖼️ Avatar image, with a CSS-initials fallback if the URL 404s/errors at runtime -->
                                <img
                                    src="<?php echo htmlspecialchars($navAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                    alt=""
                                    class="rounded-circle me-2"
                                    style="width: 36px; height: 36px; object-fit: cover;"
                                    loading="lazy"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
                                >
                                <span
                                    class="rounded-circle me-2 d-none align-items-center justify-content-center bg-primary text-white"
                                    style="width: 36px; height: 36px; font-size: 0.85rem; font-weight: 600;"
                                    aria-hidden="true"
                                ><?php echo $navInitial; ?></span>
                            <?php else: ?>
                                <!-- 🔤 No avatar available at all — CSS initials -->
                                <span
                                    class="rounded-circle me-2 d-inline-flex align-items-center justify-content-center bg-primary text-white"
                                    style="width: 36px; height: 36px; font-size: 0.85rem; font-weight: 600;"
                                    role="img"
                                    aria-label="Avatar for <?php echo $navDisplayName; ?>"
                                ><?php echo $navInitial; ?></span>
                            <?php endif; ?>
                            <span class="d-none d-md-inline"><?php echo $navDisplayName; ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="appUserDropdown">
                            <li>
                                <a class="dropdown-item" href="/dashboard">
                                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="/settings/profile">
                                    <i class="fas fa-user me-2"></i>Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="/settings">
                                    <i class="fas fa-cog me-2"></i>Settings
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="/logout">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a>
                            </li>
                        </ul>
                    </div>

                <?php else: ?>

                    <!-- 🚪 Guest state — reached by auth/passwordless-*.php and auth/passkey-login.php before a session exists -->
                    <div class="d-flex align-items-center">
                        <a href="/login" class="btn btn-outline-primary me-2">
                            <i class="fas fa-sign-in-alt me-1"></i>Login
                        </a>
                        <a href="/register" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i>Sign Up
                        </a>
                    </div>

                <?php endif; ?>

            </div>
        </nav>
    </header>
<?php
// ⚠️ Intentionally NO closing tags here (</body></html>) and NO opening
// wrapper (<main>/<div class="container">) — see the "Nesting contract"
// note in this file's header doc-block. footer.php closes what THIS file
// opened (the <html>/<body>/<header> above); every consumer page opens
// and closes its own content container in between.
