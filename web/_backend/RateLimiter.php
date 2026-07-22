<?php
declare(strict_types=1);
/**
 * ============================================================================
 * 🚦 SIGNula - Rate Limiter Bootstrap Shim (for public_html pages)
 * ============================================================================
 *
 * Purpose:
 *   🐛 B-054 (follow-up). web/public_html/admin/security/rate-limits.php
 *   requires this exact file (dirname(__DIR__, 3) . DIRECTORY_SEPARATOR .
 *   '_backend' . DIRECTORY_SEPARATOR . 'RateLimiter.php') and then does
 *   `new RateLimiter($db)` — but it was NEVER committed to the repo, so the
 *   page fataled on `require_once`. Same class of bug as the missing
 *   _backend/Database.php + _backend/SessionManager.php shims.
 *
 *   This file is intentionally a SHIM, not a second implementation. The
 *   canonical `RateLimiter` class lives at
 *   web/private_html/security/RateLimiter.php. Declaring a SECOND
 *   `class RateLimiter` here would be a fatal redeclaration error, so this
 *   file must NEVER declare the class itself — its only job is to make the
 *   `_backend/RateLimiter.php` require path resolve and guarantee the symbol
 *   exists, by loading the real class from its true location.
 *
 * @package    SIGNula
 * @subpackage Backend
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        web/private_html/security/RateLimiter.php — canonical RateLimiter class
 * @see        web/_backend/Database.php — sibling shim (same idiom)
 * ============================================================================
 */

// 🚫 Prevent direct access — guard idiom copied verbatim from
// web/private_html/security/RateLimiter.php / web/_backend/Database.php.
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🔗 Defensive require: guarantee `class RateLimiter` exists by loading it
// from its canonical location. Guarded with class_exists() so a caller that
// already loaded it is left untouched — never a double-load, never a
// redeclare.
if (!class_exists('RateLimiter')) {
    // web/_backend/RateLimiter.php -> dirname(__DIR__) = web/
    // web/private_html/security/RateLimiter.php <- + private_html/security/RateLimiter.php
    $backendRateLimiterPath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'security'
        . DIRECTORY_SEPARATOR . 'RateLimiter.php';

    if (is_file($backendRateLimiterPath)) {
        require_once $backendRateLimiterPath;
    }
}

// ✅ Shim loaded successfully — `RateLimiter` is guaranteed available to the
// caller (assuming the canonical file is present at its expected path).
