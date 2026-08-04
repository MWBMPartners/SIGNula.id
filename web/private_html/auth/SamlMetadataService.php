<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - SAML 2.0 Identity-Provider: Metadata Document Builder (#100)
 * ============================================================================
 *
 * Purpose:
 *   Pure(ish) builder for the SAML 2.0 IdP metadata document
 *   (`<md:EntityDescriptor>`/`<md:IDPSSODescriptor>`) served at
 *   `GET /saml/metadata`, plus the shared `isSamlEnabled()` master-switch
 *   gate every `/saml/*` controller checks FIRST — the exact
 *   `OidcDiscoveryService::isProviderEnabled()` twin (including the same
 *   `filter_var(FILTER_VALIDATE_BOOLEAN)` handling), applied to
 *   `saml.enabled` instead of `oidc.enabled`.
 *
 *   Kept as a standalone builder (not a method on
 *   SamlServiceProviderManager or SamlKeyManager) so it is trivially
 *   Unit-testable — call {@see self::buildIdpMetadataDocument()} directly
 *   and assert the returned DOMDocument's structure via DOMXPath, without a
 *   real HTTP request. Mirrors the "thin controller, testable engine" split
 *   {@see OidcDiscoveryService} uses for `/.well-known/openid-configuration`.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-dom.
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-metadata-2.0-os.pdf (SAML 2.0 Metadata)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf (SAML 2.0 Core — §8.3 NameID Format Identifiers)
 *
 * @see web/public_html/saml/metadata.php (the thin controller that consumes this class)
 * @see web/private_html/security/SamlKeyManager.php (getAllCertificates() feeds the KeyDescriptor elements)
 * @see web/private_html/auth/OidcDiscoveryService.php (the sibling this class's gate/shape mirrors)
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
 * 🪪 SAML IdP metadata-document builder + the `saml.enabled` dormant gate.
 */
class SamlMetadataService
{
    /** @var string SAML metadata namespace. */
    private const MD_NS = 'urn:oasis:names:tc:SAML:2.0:metadata';

    /** @var string XML-DSig namespace (for KeyDescriptor/KeyInfo). */
    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    /** @var string The SAML 2.0 protocol URN advertised in protocolSupportEnumeration. */
    private const PROTOCOL_NS = 'urn:oasis:names:tc:SAML:2.0:protocol';

    /**
     * 🗺️ tblSAMLServiceProviders.nameIDFormat ENUM value => the full SAML
     * NameID Format URI (SAML 2.0 Core §8.3). Public + shared with
     * {@see SamlResponseBuilder} (the single source of truth for this
     * mapping — never duplicated).
     *
     * @var array<string,string>
     */
    public const NAMEID_FORMAT_URIS = [
        'emailAddress' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        'persistent'   => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'transient'    => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
        'unspecified'  => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
    ];

    /** @var string HTTP-Redirect binding URN. */
    public const BINDING_HTTP_REDIRECT = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';

    /** @var string HTTP-POST binding URN. */
    public const BINDING_HTTP_POST = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';

    // ========================================================================
    // 🚦 THE DORMANT GATE
    // ========================================================================

    /**
     * 🔒 Is the SAML 2.0 Identity-Provider surface enabled at all?
     *
     * Migration 050 seeds `saml.enabled = '0'` (master switch, default OFF
     * until an operator opts in — and, per this feature's plan, not before
     * staging interop + a red-team pass are evidenced). This is the single
     * shared gate every `/saml/*` controller calls at the very top of the
     * request, BEFORE any DB-bound SP/key work — the exact
     * `OidcDiscoveryService::isProviderEnabled()` pattern (G-001 red-team
     * F-05 fix), applied to this protocol.
     *
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN)` (not a bare truthy check)
     * so every shape `getSetting()` can hand back resolves to the SAME
     * correct boolean. Defaults to `false` (OFF) when the setting is
     * entirely absent.
     *
     * @return bool True only when the SAML IdP is explicitly enabled.
     */
    public static function isSamlEnabled(): bool
    {
        return filter_var(getSetting('saml.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    // ========================================================================
    // 🏗️ METADATA DOCUMENT
    // ========================================================================

    /**
     * 🏗️ Build the full SAML 2.0 IdP metadata document.
     *
     * Every endpoint URL is derived from `saml.idp_entity_id`'s scheme+host
     * (never a hardcoded host) so the document stays correct across
     * environments without a code change — mirrors how
     * {@see OidcDiscoveryService::buildDocument()} derives every URL from
     * `oidc.issuer`.
     *
     * Publishes ONE `<md:KeyDescriptor use="signing">` per certificate
     * {@see SamlKeyManager::getAllCertificates()} currently has on file
     * (active + not-yet-retired) — during a key-rotation overlap window
     * this means TWO KeyDescriptor elements, matching plan §6.1's rotation
     * lifecycle (SPs cache metadata, so the old cert must stay published
     * until {@see SamlKeyManager::retireKey()} is explicitly called).
     *
     * @return DOMDocument The metadata document (call ->saveXML() to serialise).
     * @throws \RuntimeException If no signing certificate can be obtained
     *                           (SamlKeyManager::getActiveKey() lazily mints
     *                           one, so this should only fail on a genuine
     *                           OpenSSL/DB error — see that method).
     */
    public static function buildIdpMetadataDocument(): DOMDocument
    {
        $entityId = (string) getSetting('saml.idp_entity_id', 'https://signula.id/saml/metadata');
        $base     = self::deriveBaseUrl($entityId);
        $ssoUrl   = $base . '/saml/sso';
        $sloUrl   = $base . '/saml/slo';

        // 🔑 Ensure at least the active key exists (lazy-generates on first
        //    call — see SamlKeyManager::getActiveKey()), then publish EVERY
        //    currently-stored certificate (rotation overlap).
        SamlKeyManager::getActiveKey();
        $certificates = SamlKeyManager::getAllCertificates();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false; // 🚫 No pretty-print whitespace inside a document a verifier may C14N.

        $entityDescriptor = $dom->createElementNS(self::MD_NS, 'md:EntityDescriptor');
        $entityDescriptor->setAttribute('entityID', $entityId);
        $dom->appendChild($entityDescriptor);

        $idpSsoDescriptor = $dom->createElementNS(self::MD_NS, 'md:IDPSSODescriptor');
        $idpSsoDescriptor->setAttribute('protocolSupportEnumeration', self::PROTOCOL_NS);
        $idpSsoDescriptor->setAttribute(
            'WantAuthnRequestsSigned',
            filter_var(getSetting('saml.require_signed_authnrequests', false), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'
        );
        $entityDescriptor->appendChild($idpSsoDescriptor);

        // 🔏 One KeyDescriptor per currently-stored signing certificate.
        foreach ($certificates as $certPem) {
            $idpSsoDescriptor->appendChild(self::buildKeyDescriptor($dom, $certPem));
        }

        // 🧾 NameID formats this IdP can assert (all four migration 050 supports).
        foreach (self::NAMEID_FORMAT_URIS as $formatUri) {
            $node = $dom->createElementNS(self::MD_NS, 'md:NameIDFormat', $formatUri);
            $idpSsoDescriptor->appendChild($node);
        }

        // 🚪 SingleSignOnService — both bindings point at the SAME /saml/sso
        //    controller, which dispatches on $_SERVER['REQUEST_METHOD'].
        $idpSsoDescriptor->appendChild(self::buildEndpoint($dom, 'md:SingleSignOnService', self::BINDING_HTTP_REDIRECT, $ssoUrl));
        $idpSsoDescriptor->appendChild(self::buildEndpoint($dom, 'md:SingleSignOnService', self::BINDING_HTTP_POST, $ssoUrl));

        // 🚪 SingleLogoutService — v1 supports the Redirect binding first-class
        //    (plan §7 "SLO (v1)"); advertising POST too costs nothing since
        //    the /saml/slo controller can accept either.
        $idpSsoDescriptor->appendChild(self::buildEndpoint($dom, 'md:SingleLogoutService', self::BINDING_HTTP_REDIRECT, $sloUrl));
        $idpSsoDescriptor->appendChild(self::buildEndpoint($dom, 'md:SingleLogoutService', self::BINDING_HTTP_POST, $sloUrl));

        return $dom;
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🔏 Build one `<md:KeyDescriptor use="signing">` wrapping a certificate.
     *
     * @param DOMDocument $dom     Owning document (elements are created via it).
     * @param string      $certPem Certificate PEM (headers/newlines stripped for the X509Certificate element body).
     * @return DOMElement
     */
    private static function buildKeyDescriptor(DOMDocument $dom, string $certPem): DOMElement
    {
        $keyDescriptor = $dom->createElementNS(self::MD_NS, 'md:KeyDescriptor');
        $keyDescriptor->setAttribute('use', 'signing');

        $keyInfo = $dom->createElementNS(self::DS_NS, 'ds:KeyInfo');
        $keyDescriptor->appendChild($keyInfo);

        $x509Data = $dom->createElementNS(self::DS_NS, 'ds:X509Data');
        $keyInfo->appendChild($x509Data);

        $x509Certificate = $dom->createElementNS(self::DS_NS, 'ds:X509Certificate', self::stripPemArmor($certPem));
        $x509Data->appendChild($x509Certificate);

        return $keyDescriptor;
    }

    /**
     * 🚪 Build a `<md:SingleSignOnService>`/`<md:SingleLogoutService>`-shaped endpoint element.
     *
     * @param DOMDocument $dom       Owning document.
     * @param string      $localName 'md:SingleSignOnService' or 'md:SingleLogoutService'.
     * @param string      $binding   Binding URN.
     * @param string      $location  Endpoint URL.
     * @return DOMElement
     */
    private static function buildEndpoint(DOMDocument $dom, string $localName, string $binding, string $location): DOMElement
    {
        $node = $dom->createElementNS(self::MD_NS, $localName);
        $node->setAttribute('Binding', $binding);
        $node->setAttribute('Location', $location);

        return $node;
    }

    /**
     * 🧹 Strip PEM armor ("-----BEGIN/END CERTIFICATE-----" + newlines) so
     * only the base64 DER body remains — the shape `<ds:X509Certificate>`
     * expects (SAML/XML-DSig do not embed PEM headers).
     *
     * @param string $pem Certificate PEM.
     * @return string Base64 DER body, whitespace-stripped.
     */
    private static function stripPemArmor(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $pem) ?? $pem;

        return preg_replace('/\s+/', '', $body) ?? '';
    }

    /**
     * 🌐 Derive `scheme://host[:port]` from the configured entityID URL.
     *
     * Falls back to a conservative default if `saml.idp_entity_id` is
     * somehow unparsable (never fatals a metadata request over a malformed
     * setting an admin can fix later).
     *
     * @param string $entityId Configured `saml.idp_entity_id`.
     * @return string
     */
    private static function deriveBaseUrl(string $entityId): string
    {
        $parts = parse_url($entityId);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'https://signula.id';
        }

        $base = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        return $base;
    }
}

// ✅ SamlMetadataService loaded successfully
