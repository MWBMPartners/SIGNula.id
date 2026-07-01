<?php
/**
 * ============================================================================
 * 🔑 SIGNula - firebase/php-jwt Vendored Library Loader
 * ============================================================================
 *
 * Purpose:
 *   Loads the self-hosted (source-vendored) firebase/php-jwt library from
 *   web/_lib/jwt/src/ WITHOUT relying on Composer's autoloader at runtime.
 *
 *   The project runs on Dreamhost shared hosting with no CLI / no Composer in
 *   production, so — exactly like web/_lib/tcpdf (TCPDFLoader) and
 *   web/_lib/webauthn — the dependency is vendored as plain PHP source and
 *   require_once'd here in dependency order, each guarded by file_exists().
 *
 *   The classes live in the `Firebase\JWT\` namespace. Nothing in SIGNula calls
 *   `Firebase\JWT\*` directly EXCEPT this loader, the Jwt facade
 *   (web/private_html/security/Jwt.php) and KeyManager — that single choke point
 *   means the library can be swapped without touching the rest of the codebase.
 *
 * Vendored version: firebase/php-jwt 6.11.1 (BSD-3-Clause). See:
 *   - web/_lib/jwt/VERSION
 *   - web/_lib/jwt/LICENSE
 *
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.8.0-beta (G-003 Stage 1)
 * @link       https://github.com/firebase/php-jwt
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔑 JWT Library Loader
 *
 * Static, idempotent loader for the vendored firebase/php-jwt source.
 * Mirrors the TCPDFLoader pattern (web/_lib/tcpdf/tcpdf_loader.php).
 */
class JwtLibLoader
{
    /** @var bool Whether the library has already been loaded this request. */
    private static bool $loaded = false;

    /**
     * 📜 The vendored files, in dependency-safe require order.
     *
     * Order rationale:
     *   1. The exception-payload interface must exist before the exception
     *      classes that `implements` it.
     *   2. The exception classes and Key must exist before JWT.php, which
     *      references them.
     *   3. JWK.php is independent (depends only on Key) — loaded last.
     *
     * @var string[]
     */
    private const FILES = [
        'JWTExceptionWithPayloadInterface.php',
        'BeforeValidException.php',
        'ExpiredException.php',
        'SignatureInvalidException.php',
        'Key.php',
        'JWT.php',
        'JWK.php',
    ];

    /**
     * 📁 Absolute path to the vendored src/ directory.
     *
     * Uses the LIB_DIR constant (defined in web/_config/config.php) so paths are
     * platform-neutral. Falls back to a path relative to this file when LIB_DIR
     * is not defined (e.g. isolated unit tests that load this file directly).
     *
     * @return string
     */
    private static function srcDir(): string
    {
        if (defined('LIB_DIR')) {
            return LIB_DIR
                . DIRECTORY_SEPARATOR . 'jwt'
                . DIRECTORY_SEPARATOR . 'src';
        }

        // 🔄 Fallback: this file lives at web/private_html/security/, and the
        //    library at web/_lib/jwt/src/ — walk up two dirs to web/ then down.
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . '_lib'
            . DIRECTORY_SEPARATOR . 'jwt'
            . DIRECTORY_SEPARATOR . 'src';
    }

    /**
     * 🔍 Is the vendored library present on disk?
     *
     * @return bool True when every required source file exists.
     */
    public static function isAvailable(): bool
    {
        $dir = self::srcDir();

        foreach (self::FILES as $file) {
            if (!file_exists($dir . DIRECTORY_SEPARATOR . $file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 📦 Load the vendored library.
     *
     * Idempotent: safe to call repeatedly (the first successful load short-
     * circuits every later call). Throws a RuntimeException on the FIRST missing
     * file so a broken/partial vendoring fails loudly rather than producing a
     * confusing "class not found" later — this is security-critical code and a
     * silent partial load must never happen.
     *
     * @return void
     * @throws RuntimeException If a required vendored file is missing.
     */
    public static function load(): void
    {
        // ⏩ Already loaded (or classes already present via another path).
        if (self::$loaded || class_exists('Firebase\\JWT\\JWT', false)) {
            self::$loaded = true;
            return;
        }

        $dir = self::srcDir();

        foreach (self::FILES as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;

            // 🛡️ Fail-closed: a missing crypto file is never "graceful".
            if (!file_exists($path)) {
                throw new RuntimeException(
                    'JWT library file missing: ' . $path
                    . ' — re-vendor firebase/php-jwt into web/_lib/jwt/ (see web/_lib/jwt/VERSION).'
                );
            }

            require_once $path;
        }

        // ✅ Sanity-check the two classes the facade actually needs.
        if (!class_exists('Firebase\\JWT\\JWT', false)
            || !class_exists('Firebase\\JWT\\Key', false)) {
            throw new RuntimeException(
                'JWT library loaded but Firebase\\JWT\\JWT / Key not defined — vendored copy is corrupt.'
            );
        }

        self::$loaded = true;
    }
}

// ✅ JwtLibLoader loaded successfully
