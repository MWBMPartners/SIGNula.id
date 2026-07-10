<?php
declare(strict_types=1);
/**
 * ============================================================================
 * 🔑 SIGNula - API Key Manager Bootstrap Shim (for public_html pages)
 * ============================================================================
 *
 * Purpose:
 *   🐛 B-054 (follow-up). web/public_html/partners/api-keys.php requires this
 *   exact file (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_backend' .
 *   DIRECTORY_SEPARATOR . 'APIKeyManager.php') and then does
 *   `new APIKeyManager($db)` — but it was NEVER committed to the repo, so the
 *   page fataled on `require_once`. Same class of bug as the missing
 *   _backend/Database.php + _backend/SessionManager.php shims.
 *
 *   This file is intentionally a SHIM, not a second implementation. The
 *   canonical `APIKeyManager` class lives at
 *   web/private_html/api/APIKeyManager.php. Declaring a SECOND
 *   `class APIKeyManager` here would be a fatal redeclaration error, so this
 *   file must NEVER declare the class itself — its only job is to make the
 *   `_backend/APIKeyManager.php` require path resolve and guarantee the
 *   symbol exists, by loading the real class from its true location.
 *
 * @package    SIGNula
 * @subpackage Backend
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        web/private_html/api/APIKeyManager.php — canonical APIKeyManager class
 * @see        web/_backend/Database.php — sibling shim (same idiom)
 * ============================================================================
 */

// 🚫 Prevent direct access — guard idiom copied verbatim from
// web/private_html/api/APIKeyManager.php / web/_backend/Database.php.
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🔗 Defensive require: guarantee `class APIKeyManager` exists by loading it
// from its canonical location. Guarded with class_exists() so a caller that
// already loaded it is left untouched — never a double-load, never a
// redeclare.
if (!class_exists('APIKeyManager')) {
    // web/_backend/APIKeyManager.php -> dirname(__DIR__) = web/
    // web/private_html/api/APIKeyManager.php <- + private_html/api/APIKeyManager.php
    $backendAPIKeyManagerPath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'APIKeyManager.php';

    if (is_file($backendAPIKeyManagerPath)) {
        require_once $backendAPIKeyManagerPath;
    }
}

// ✅ Shim loaded successfully — `APIKeyManager` is guaranteed available to
// the caller (assuming the canonical file is present at its expected path).
