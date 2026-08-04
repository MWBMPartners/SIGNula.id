<?php
/**
 * ============================================================================
 * 🪪 SIGNula - SAML 2.0 IdP Metadata Document  (GET /saml/metadata)
 * ============================================================================
 *
 * Publishes SIGNula's SAML 2.0 Identity-Provider metadata
 * (`<md:EntityDescriptor>`/`<md:IDPSSODescriptor>`) so a SAML Service
 * Provider can auto-configure itself: the signing certificate(s), NameID
 * formats, and the SingleSignOnService/SingleLogoutService endpoints — the
 * SAML analogue of `/.well-known/openid-configuration` (G-001 Phase B, #100).
 *
 * 🚦 DORMANT BY DEFAULT: gated behind `saml.enabled` (seeded '0' by
 * migration 050). While disabled, this endpoint 404s exactly like the OIDC
 * discovery document does when `oidc.enabled` is off — a disabled IdP does
 * not even confirm this endpoint exists. See PRE_LAUNCH_REVIEW.md before
 * ever flipping `saml.enabled` to '1'.
 *
 * URL: /saml/metadata (web/public_html/.htaccess's generic clean-URL
 *      rewrite maps this straight to this file — no dedicated rewrite rule
 *      needed, exactly like `/oauth/authorize-idp` -> `oauth/authorize-idp.php`).
 * Method: GET (and HEAD) only — a metadata document is, by definition, public.
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage SAML
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @see web/private_html/auth/SamlMetadataService.php (buildIdpMetadataDocument())
 * @see web/public_html/.well-known/openid-configuration/index.php (the sibling this controller's shape mirrors)
 * ============================================================================
 */

// 🚀 Initialize SIGNula (config.php defines SIGNULA_INIT and loads all classes)
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 🔒 THE DORMANT GATE — `saml.enabled` (migration 050, default '0')
// ============================================================================
// Mirrors the `oidc.enabled` / OidcDiscoveryService::isProviderEnabled() gate
// (G-001 red-team F-05 fix): checked FIRST, before any DB-bound key/SP work,
// so a disabled IdP costs nothing beyond this one settings lookup.
if (!class_exists('SamlMetadataService') || !SamlMetadataService::isSamlEnabled()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_found', 'message' => 'SAML IdP is not enabled.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

try {
    if (!class_exists('SamlMetadataService')) {
        throw new RuntimeException('SamlMetadataService unavailable');
    }

    $dom = SamlMetadataService::buildIdpMetadataDocument();

    http_response_code(200);
    // 📄 The IANA-registered SAML metadata media type.
    header('Content-Type: application/samlmetadata+xml; charset=utf-8');
    // 🕐 Cacheable — the document only changes on a settings/SP/key change
    //    (a rare admin action), mirrors the JWKS/OIDC-discovery caching policy.
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    echo $dom->saveXML();
    exit;
} catch (Throwable $e) {
    // 🔇 Never leak internals — log server-side, generic 500 to the caller.
    if (class_exists('ErrorLogger')) {
        try {
            ErrorLogger::log($e);
        } catch (Throwable $ignored) {
            error_log('SAML metadata endpoint error: ' . $e->getMessage());
        }
    } else {
        error_log('SAML metadata endpoint error: ' . $e->getMessage());
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'service_unavailable']);
    exit;
}
