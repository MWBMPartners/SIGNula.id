<?php
declare(strict_types=1);
/**
 * ============================================================================
 * 🗄️ SIGNula - Database Bootstrap Shim (for public_html pages)
 * ============================================================================
 *
 * Purpose:
 *   🐛 B-054 fix. 74 pages under web/public_html require this exact file
 *   (dirname(__DIR__, N) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR
 *   . 'Database.php') immediately after config.php and before
 *   _backend/SessionManager.php — but it was NEVER committed to the repo, so
 *   every one of those pages fataled on `require_once` with "Failed opening
 *   required ... _backend/Database.php" (one require earlier in the same
 *   bootstrap chain than the B-051 SessionManager.php fix).
 *
 *   This file is intentionally a SHIM, not a second implementation. The
 *   canonical, fully-featured database layer — the STATIC `Database` class
 *   (Database::query()/fetchOne()/fetchAll()/...) PLUS the `getInstance()`
 *   factory + `DatabaseConnection` instance-adapter that make
 *   `$db = Database::getInstance(); $db->prepare(...);` work — all live in
 *   web/_config/database.php, which web/_config/config.php already
 *   `require_once`s on every request (see config.php's "LOAD DATABASE
 *   CONNECTION" section). By the time a public_html page reaches THIS
 *   file's require line, `class Database` is therefore already defined.
 *
 *   Declaring a SECOND `class Database` here would be a fatal redeclaration
 *   error (PHP does not allow two classes with the same name in one
 *   request), so this file must NEVER declare `class Database` itself. Its
 *   only job is to guarantee the symbol exists — for the ordinary case
 *   (config.php already loaded it) that's a no-op; for any caller that
 *   somehow reaches this file without config.php having run first, it loads
 *   the real implementation defensively.
 *
 * @package    SIGNula
 * @subpackage Backend
 * @version    1.0.0
 * @link       https://SIGNula.id
 * @see        web/_config/database.php   — canonical Database class + DatabaseConnection adapter
 * @see        web/_backend/SessionManager.php — sibling shim fixed under B-051 (same idiom)
 * ============================================================================
 */

// 🚫 Prevent direct access — guard idiom copied verbatim from
// web/private_html/auth/Auth.php / web/_config/database.php / web/_backend/SessionManager.php.
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🔗 Defensive require: guarantee `class Database` (and its getInstance()/
// DatabaseConnection companions) exist even if this file is ever reached
// without web/_config/config.php having run first. Guarded with
// class_exists() so the NORMAL case — config.php already
// require_once'd _config/database.php — is a true no-op here (never a
// double-load, never a redeclare).
if (!class_exists('Database')) {
    // web/_backend/Database.php -> dirname(__DIR__) = web/
    // web/_config/database.php  <- + _config/database.php
    $backendDatabasePath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . '_config'
        . DIRECTORY_SEPARATOR . 'database.php';

    if (is_file($backendDatabasePath)) {
        require_once $backendDatabasePath;
    }
}

// ✅ Shim loaded successfully — `Database` (static API) and, once
// web/_config/database.php has executed, `DatabaseConnection` +
// `Database::getInstance()` are guaranteed to be available to the caller.
