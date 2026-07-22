<?php
declare(strict_types=1);
/**
 * ============================================================================
 * 📝 SIGNula - Activity Logger Bootstrap Shim (for public_html pages)
 * ============================================================================
 *
 * Purpose:
 *   🐛 B-054 (follow-up). 10 pages under web/public_html require this exact
 *   file (dirname(__DIR__, N) . DIRECTORY_SEPARATOR . '_backend' .
 *   DIRECTORY_SEPARATOR . 'ActivityLogger.php') — but it was NEVER committed
 *   to the repo, so 9 of them fataled on `require_once` with "Failed opening
 *   required ... _backend/ActivityLogger.php" (the 10th only referenced the
 *   class behind a class_exists() guard, so it silently skipped audit
 *   logging instead of fataling). Same class of bug as the missing
 *   _backend/Database.php + _backend/SessionManager.php shims.
 *
 *   This file is intentionally a SHIM, not a second implementation. The
 *   canonical `ActivityLogger` class lives at
 *   web/private_html/utils/ActivityLogger.php. Declaring a SECOND
 *   `class ActivityLogger` here would be a fatal redeclaration error, so
 *   this file must NEVER declare the class itself — its only job is to make
 *   the `_backend/ActivityLogger.php` require path resolve and guarantee the
 *   symbol exists, by loading the real class from its true location.
 *
 * @package    SIGNula
 * @subpackage Backend
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        web/private_html/utils/ActivityLogger.php — canonical ActivityLogger class
 * @see        web/_backend/Database.php — sibling shim (same idiom)
 * ============================================================================
 */

// 🚫 Prevent direct access — guard idiom copied verbatim from
// web/private_html/utils/ActivityLogger.php / web/_backend/Database.php.
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🔗 Defensive require: guarantee `class ActivityLogger` exists by loading it
// from its canonical location. Guarded with class_exists() so a caller that
// already loaded it (e.g. via config.php's autoloader, or another page in a
// long-running context) is left untouched — never a double-load, never a
// redeclare.
if (!class_exists('ActivityLogger')) {
    // web/_backend/ActivityLogger.php -> dirname(__DIR__) = web/
    // web/private_html/utils/ActivityLogger.php <- + private_html/utils/ActivityLogger.php
    $backendActivityLoggerPath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'utils'
        . DIRECTORY_SEPARATOR . 'ActivityLogger.php';

    if (is_file($backendActivityLoggerPath)) {
        require_once $backendActivityLoggerPath;
    }
}

// ✅ Shim loaded successfully — `ActivityLogger` is guaranteed available to
// the caller (assuming the canonical file is present at its expected path).
