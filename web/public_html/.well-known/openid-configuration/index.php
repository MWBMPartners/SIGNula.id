<?php
/**
 * ============================================================================
 * 🪪 SIGNula - OIDC Discovery Document  (GET /.well-known/openid-configuration)
 * ============================================================================
 *
 * Publishes SIGNula's OIDC Provider metadata (OpenID Connect Discovery 1.0
 * §3), so ANY OIDC-compliant Relying Party — including a standard third-party
 * OIDC client library that has never seen SIGNula-specific docs — can
 * auto-configure itself: discover the authorize/token/userinfo/revocation
 * endpoints, the JWKS location, and the supported scopes/algorithms/PKCE
 * methods, all from one well-known URL.
 *
 * URL resolution: this file lives at
 * `.well-known/openid-configuration/index.php` (a DIRECTORY literally named
 * `openid-configuration`), so a request to `/.well-known/openid-configuration`
 * resolves to the directory index — EXACTLY the same trick
 * `.well-known/jwks.json/index.php` already uses (G-003 Stage 3). The
 * co-located `.htaccess` re-allows the path (the site-root `.htaccess`
 * otherwise forbids dot-directories) and disables the clean-URL rewriting
 * inside `.well-known/`.
 *
 * Caching: the document only changes when OIDC policy settings change (a rare
 * admin action), so it is safely cacheable (`Cache-Control: public,
 * max-age=3600`) — mirrors the JWKS endpoint's caching policy.
 *
 * No authentication — discovery documents are, by definition, public.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage API
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A4)
 * @link       https://openid.net/specs/openid-connect-discovery-1_0.html (OIDC Discovery 1.0)
 * @see        web/private_html/auth/OidcDiscoveryService.php (buildDocument())
 * @see        web/public_html/.well-known/jwks.json/index.php (the sibling public-JWKS endpoint)
 * @see        .dev-team/specs/G-001.md §6
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🔧 Bootstrap the application (defines constants, loads settings + autoloader).
//    SIGNULA_INIT must be set BEFORE config.php so the guarded source files load.
define('SIGNULA_INIT', true);

// This file is web/public_html/.well-known/openid-configuration/index.php →
// config.php is four directories up (openid-configuration → .well-known →
// public_html → web) then _config — same depth as jwks.json/index.php.
$configPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

if (!is_file($configPath)) {
    // Cannot bootstrap — fail closed with a generic 500 (never leak paths).
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'service_unavailable']);
    exit;
}

require_once $configPath;

// 📤 Always JSON, and cacheable (the document only changes with OIDC policy).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

// 🔎 Only GET (and HEAD) make sense for a public metadata document.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

try {
    if (!class_exists('OidcDiscoveryService')) {
        throw new RuntimeException('OidcDiscoveryService unavailable');
    }

    $document = OidcDiscoveryService::buildDocument();

    http_response_code(200);
    echo json_encode($document, JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    // 🔇 Never leak internals. Log server-side; return a generic 500 rather
    //    than hand attackers a stack trace or partial document.
    if (class_exists('ErrorLogger')) {
        try {
            ErrorLogger::log($e);
        } catch (Throwable $ignored) {
            error_log('OIDC discovery endpoint error: ' . $e->getMessage());
        }
    } else {
        error_log('OIDC discovery endpoint error: ' . $e->getMessage());
    }

    http_response_code(500);
    echo json_encode(['error' => 'service_unavailable']);
    exit;
}
