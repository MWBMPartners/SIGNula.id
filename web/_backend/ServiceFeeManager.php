<?php
declare(strict_types=1);
/**
 * ============================================================================
 * 💰 SIGNula - Service Fee Manager Bootstrap Shim (for public_html pages)
 * ============================================================================
 *
 * Purpose:
 *   🐛 B-054 (follow-up). web/public_html/admin/api/service-fee-actions.php
 *   loads this exact file (dirname(__DIR__, 3) . DIRECTORY_SEPARATOR .
 *   '_backend' . DIRECTORY_SEPARATOR . 'ServiceFeeManager.php') and then uses
 *   ServiceFeeManager::* static methods — but it was NEVER committed to the
 *   repo. (That page currently guards the load behind an is_file()/class_exists
 *   check and falls back, so it degrades rather than fatals; other future
 *   callers that require the path directly would fatal.) Same class of bug as
 *   the missing _backend/Database.php + _backend/SessionManager.php shims.
 *
 *   This file is intentionally a SHIM, not a second implementation. The
 *   canonical `ServiceFeeManager` class lives at
 *   web/private_html/payments/ServiceFeeManager.php. Declaring a SECOND
 *   `class ServiceFeeManager` here would be a fatal redeclaration error, so
 *   this file must NEVER declare the class itself — its only job is to make
 *   the `_backend/ServiceFeeManager.php` require path resolve and guarantee
 *   the symbol exists, by loading the real class from its true location.
 *
 * @package    SIGNula
 * @subpackage Backend
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        web/private_html/payments/ServiceFeeManager.php — canonical ServiceFeeManager class
 * @see        web/_backend/Database.php — sibling shim (same idiom)
 * ============================================================================
 */

// 🚫 Prevent direct access — guard idiom copied verbatim from
// web/private_html/payments/ServiceFeeManager.php / web/_backend/Database.php.
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🔗 Defensive require: guarantee `class ServiceFeeManager` exists by loading
// it from its canonical location. Guarded with class_exists() so a caller
// that already loaded it is left untouched — never a double-load, never a
// redeclare.
if (!class_exists('ServiceFeeManager')) {
    // web/_backend/ServiceFeeManager.php -> dirname(__DIR__) = web/
    // web/private_html/payments/ServiceFeeManager.php <- + private_html/payments/ServiceFeeManager.php
    $backendServiceFeeManagerPath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'payments'
        . DIRECTORY_SEPARATOR . 'ServiceFeeManager.php';

    if (is_file($backendServiceFeeManagerPath)) {
        require_once $backendServiceFeeManagerPath;
    }
}

// ✅ Shim loaded successfully — `ServiceFeeManager` is guaranteed available to
// the caller (assuming the canonical file is present at its expected path).
