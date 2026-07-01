<?php
/**
 * ============================================================================
 * 🔑 SIGNula - JWKS Endpoint  (GET /.well-known/jwks.json)  — G-003
 * ============================================================================
 *
 * Publishes the PUBLIC half of SIGNula's JWT signing keys as a standard JWK Set
 * (RFC 7517), so ANY verifier — future G-001 relying parties, third-party
 * services, or an external test using a different JWT library — can validate an
 * RS256 access token WITHOUT holding any shared secret. This is the shared
 * contract the whole asymmetric-JWT model rests on.
 *
 * 🔒 Public keys ONLY. KeyManager::getJwks() emits {kty,use,alg,kid,n,e} for
 *    each currently-valid key and explicitly refuses any JWK carrying a private
 *    exponent `d`. This handler ADDS a belt-and-suspenders sweep that strips any
 *    private component (`d`,`p`,`q`,`dp`,`dq`,`qi`) before output — a private
 *    key must NEVER leave this process.
 *
 * URL resolution: this file lives at `.well-known/jwks.json/index.php` (a
 * DIRECTORY literally named `jwks.json`), so a request to `/.well-known/jwks.json`
 * resolves to the directory index. The co-located `.htaccess` re-allows the path
 * (the site-root `.htaccess` otherwise forbids dot-directories) and disables the
 * clean-URL rewriting inside `.well-known/`.
 *
 * Caching: JWKS changes only on key rotation, so it is safely cacheable
 * (`Cache-Control: public, max-age=3600`). Verifiers refetch on an unknown kid.
 * No authentication — the whole point is public verifiability.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage API
 * @version    1.0.0
 * @since      2.8.0-beta (G-003 Stage 3)
 * @link       https://datatracker.ietf.org/doc/html/rfc7517 (JWK / JWK Set)
 * @see        web/private_html/security/KeyManager.php  (getJwks)
 * @see        .dev-team/specs/G-003.md §6
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🔧 Bootstrap the application (defines constants, loads settings + autoloader).
//    SIGNULA_INIT must be set BEFORE config.php so the guarded source files load.
define('SIGNULA_INIT', true);

// This file is web/public_html/.well-known/jwks.json/index.php → config.php is
// four directories up (jwks.json → .well-known → public_html → web) then _config.
$configPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

if (!is_file($configPath)) {
    // Cannot bootstrap — fail closed with a generic 500 (never leak paths).
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'service_unavailable']);
    exit;
}

require_once $configPath;

// 📤 Always JSON, and cacheable (JWKS only changes on key rotation).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

// 🔎 Only GET (and HEAD) make sense for a public key document.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

try {
    // 🗝️ Assemble the public JWK Set. KeyManager already filters to public RSA
    //    JWKs (kty=RSA, has n/e, has NO `d`).
    if (!class_exists('KeyManager')) {
        throw new RuntimeException('KeyManager unavailable');
    }

    $jwks = KeyManager::getJwks();

    // 🛡️ Defence-in-depth: strip ANY private component that could ever slip
    //    through, and only publish well-formed public RSA keys. A private
    //    exponent (`d`) or CRT parameter must never appear in this response.
    $privateFields = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];
    $safeKeys = [];

    foreach ($jwks['keys'] ?? [] as $key) {
        if (!is_array($key)) {
            continue;
        }

        // Drop the whole key if it carries ANY private material (never leak it).
        $hasPrivate = false;
        foreach ($privateFields as $pf) {
            if (array_key_exists($pf, $key)) {
                $hasPrivate = true;
                break;
            }
        }
        if ($hasPrivate) {
            continue;
        }

        // Only publish complete public RSA keys.
        if (($key['kty'] ?? null) === 'RSA' && isset($key['kid'], $key['n'], $key['e'])) {
            $safeKeys[] = [
                'kty' => 'RSA',
                'use' => $key['use'] ?? 'sig',
                'alg' => $key['alg'] ?? 'RS256',
                'kid' => (string) $key['kid'],
                'n'   => (string) $key['n'],
                'e'   => (string) $key['e'],
            ];
        }
    }

    http_response_code(200);
    // HEAD requests get headers only (no body) — but echoing is harmless as PHP
    // discards the body for HEAD; we keep it simple and always emit.
    echo json_encode(['keys' => $safeKeys], JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    // 🔇 Never leak internals. Log server-side; return an empty-but-valid set so
    //    a transient failure does not hand attackers a stack trace.
    if (class_exists('ErrorLogger')) {
        try {
            ErrorLogger::log($e);
        } catch (Throwable $ignored) {
            error_log('JWKS endpoint error: ' . $e->getMessage());
        }
    } else {
        error_log('JWKS endpoint error: ' . $e->getMessage());
    }

    http_response_code(200);
    echo json_encode(['keys' => []], JSON_UNESCAPED_SLASHES);
    exit;
}
