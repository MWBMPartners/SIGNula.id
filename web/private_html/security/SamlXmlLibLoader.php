<?php
/**
 * ============================================================================
 * 🔏 SIGNula - robrichards/xmlseclibs Vendored Library Loader
 * ============================================================================
 *
 * Purpose:
 *   Loads the self-hosted (source-vendored) robrichards/xmlseclibs library
 *   from web/_lib/xmlseclibs/src/ WITHOUT relying on Composer's autoloader
 *   at runtime.
 *
 *   The project runs on Dreamhost shared hosting with no CLI / no Composer in
 *   production, so — exactly like web/_lib/jwt (JwtLibLoader) and
 *   web/_lib/tcpdf (TCPDFLoader) — the dependency is vendored as plain PHP
 *   source and require_once'd here in dependency order, each guarded by
 *   file_exists().
 *
 *   The classes live in the `RobRichards\XMLSecLibs\` namespace. Nothing in
 *   SIGNula calls `RobRichards\XMLSecLibs\*` directly EXCEPT this loader and
 *   the SamlXmlSignature facade (web/private_html/security/SamlXmlSignature.php)
 *   — that single choke point means the library can be swapped (e.g. for the
 *   actively-maintained `simplesamlphp/xml-security` fork, see VERSION) without
 *   touching anything else in the codebase.
 *
 * Vendored version: robrichards/xmlseclibs 3.1.5 (BSD-3-Clause). See:
 *   - web/_lib/xmlseclibs/VERSION
 *   - web/_lib/xmlseclibs/LICENSE
 *
 * 🚦 Dormant-foundation note (issue #100): this loader is safe to include at
 *   any time — it merely defines classes. Nothing calls into XML-DSig
 *   sign/verify unless `saml.enabled` is on AND the specific call-site
 *   (SamlXmlSignature) is reached, which only happens from the /saml/*
 *   controllers behind SamlMetadataService::isSamlEnabled().
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-dom, ext-openssl.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://github.com/robrichards/xmlseclibs
 * @link       https://www.w3.org/TR/xmldsig-core1/ (XML Signature Syntax and Processing)
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
 * 🔏 xmlseclibs Library Loader
 *
 * Static, idempotent loader for the vendored robrichards/xmlseclibs source.
 * Mirrors the JwtLibLoader / TCPDFLoader pattern.
 */
class SamlXmlLibLoader
{
    /** @var bool Whether the library has already been loaded this request. */
    private static bool $loaded = false;

    /**
     * 📜 The vendored files, in dependency-safe require order.
     *
     * Order rationale:
     *   1. Utils/XPath.php is a small helper both XMLSecurityDSig.php and
     *      XMLSecEnc.php reference — loaded first.
     *   2. XMLSecurityKey.php has no internal dependency on the others —
     *      loaded next so XMLSecurityDSig.php can reference it.
     *   3. XMLSecurityDSig.php (XML-DSig sign/verify) and XMLSecEnc.php
     *      (XML-Enc — vendored but UNUSED in v1, see VERSION) both depend on
     *      Utils/XPath.php and (indirectly, at call time) XMLSecurityKey.php.
     *
     * @var string[]
     */
    private const FILES = [
        'Utils' . DIRECTORY_SEPARATOR . 'XPath.php',
        'XMLSecurityKey.php',
        'XMLSecurityDSig.php',
        'XMLSecEnc.php',
    ];

    /**
     * 📁 Absolute path to the vendored src/ directory.
     *
     * Uses the LIB_DIR constant (defined in web/_config/config.php) so paths
     * are platform-neutral. Falls back to a path relative to this file when
     * LIB_DIR is not defined (e.g. isolated unit tests that load this file
     * directly).
     *
     * @return string
     */
    private static function srcDir(): string
    {
        if (defined('LIB_DIR')) {
            return LIB_DIR
                . DIRECTORY_SEPARATOR . 'xmlseclibs'
                . DIRECTORY_SEPARATOR . 'src';
        }

        // 🔄 Fallback: this file lives at web/private_html/security/, and the
        //    library at web/_lib/xmlseclibs/src/ — walk up two dirs to web/
        //    then down.
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . '_lib'
            . DIRECTORY_SEPARATOR . 'xmlseclibs'
            . DIRECTORY_SEPARATOR . 'src';
    }

    /**
     * 🔍 Is the vendored library present on disk?
     *
     * Used by SamlXmlSignature to decide, at call time, whether to proceed
     * or throw the clear "not vendored" guidance — see that class's header.
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
     * Idempotent: safe to call repeatedly (the first successful load
     * short-circuits every later call). Throws a RuntimeException on the
     * FIRST missing file so a broken/partial vendoring fails loudly rather
     * than producing a confusing "class not found" later — this is
     * security-critical code and a silent partial load must never happen.
     *
     * @return void
     * @throws RuntimeException If a required vendored file is missing.
     */
    public static function load(): void
    {
        // ⏩ Already loaded (or classes already present via another path).
        if (self::$loaded || class_exists('RobRichards\\XMLSecLibs\\XMLSecurityDSig', false)) {
            self::$loaded = true;
            return;
        }

        $dir = self::srcDir();

        foreach (self::FILES as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;

            // 🛡️ Fail-closed: a missing crypto file is never "graceful".
            if (!file_exists($path)) {
                throw new RuntimeException(
                    'xmlseclibs library file missing: ' . $path
                    . ' — re-vendor robrichards/xmlseclibs into web/_lib/xmlseclibs/ (see web/_lib/xmlseclibs/VERSION).'
                );
            }

            require_once $path;
        }

        // ✅ Sanity-check the classes the facade actually needs.
        if (!class_exists('RobRichards\\XMLSecLibs\\XMLSecurityKey', false)
            || !class_exists('RobRichards\\XMLSecLibs\\XMLSecurityDSig', false)) {
            throw new RuntimeException(
                'xmlseclibs loaded but XMLSecurityKey / XMLSecurityDSig not defined — vendored copy is corrupt.'
            );
        }

        self::$loaded = true;
    }
}

// ✅ SamlXmlLibLoader loaded successfully
