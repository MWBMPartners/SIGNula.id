<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🚪 SIGNula - SAML 2.0 Identity-Provider: Single Logout (SLO) Service (#100)
 * ============================================================================
 *
 * Purpose:
 *   The pure/DB-bound engine behind `GET/POST /saml/slo` — SP-initiated
 *   Single Logout (G-001 Phase B, #100). v1 scope (plan §7 "SLO (v1)"):
 *   HTTP-Redirect binding first-class in BOTH directions (inbound
 *   `<samlp:LogoutRequest>` verified via {@see SamlRedirectBinding} C1;
 *   outbound `<samlp:LogoutResponse>` signed the same way — NOT XML-DSig),
 *   single-SP logout only (the ledger's `sessionIndex` column already
 *   carries what a future multi-SP IdP-propagated logout would need — v2).
 *
 * 🛡️ Same fatal-vs-safe contract as {@see SamlAuthnRequestService} —
 *   see that class's header for the full rationale. For SLO, "safe" means
 *   the SP's registered `sloURL` has been proven to exist, so a signed
 *   `<samlp:LogoutResponse>` error MAY be sent there.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-dom.
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf (SAML 2.0 Core — §3.7 Single Logout Protocol)
 *
 * @see web/private_html/security/SamlRedirectBinding.php (C1 — both directions)
 * @see web/private_html/auth/SamlAuthnRequestService.php (the sibling this class's structure mirrors)
 * @see web/public_html/saml/slo.php (the thin controller that consumes this class)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class SamlLogoutService
{
    /** @var string SAML protocol namespace (samlp:). */
    private const SAMLP_NS = 'urn:oasis:names:tc:SAML:2.0:protocol';

    /** @var string SAML assertion namespace (saml:). */
    private const SAML_NS = 'urn:oasis:names:tc:SAML:2.0:assertion';

    /** @var string SAML 2.0 "Success" top-level status code. */
    public const STATUS_SUCCESS = 'urn:oasis:names:tc:SAML:2.0:status:Success';

    /** @var string SAML 2.0 "Requester" top-level status code. */
    public const STATUS_REQUESTER = 'urn:oasis:names:tc:SAML:2.0:status:Requester';

    /** @var int Random bytes for the LogoutResponse ID — 20 bytes = 160-bit, matches SamlResponseBuilder. */
    private const ID_BYTES = 20;

    // ========================================================================
    // 🔍 PARSING
    // ========================================================================

    /**
     * 🔍 Parse a hardened `<samlp:LogoutRequest>` document (load it first via
     * {@see SamlAuthnRequestService::loadDom()} — the SAME hardened intake
     * is reused for every inbound SAML message, request or logout alike).
     *
     * @param DOMDocument $dom An already-hardened document.
     * @return array{id:string,issueInstant:string,issuer:string,destination:?string,nameId:?string,sessionIndex:?string}|null
     */
    public static function parseLogoutRequest(DOMDocument $dom): ?array
    {
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'LogoutRequest' || $root->namespaceURI !== self::SAMLP_NS) {
            return null;
        }
        if ($root->getAttribute('Version') !== '2.0') {
            return null;
        }

        $id = $root->getAttribute('ID');
        $issueInstant = $root->getAttribute('IssueInstant');
        if ($id === '' || $issueInstant === '') {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('samlp', self::SAMLP_NS);
        $xpath->registerNamespace('saml', self::SAML_NS);

        $issuerNodes = $xpath->query('./saml:Issuer', $root);
        if ($issuerNodes === false || $issuerNodes->length !== 1) {
            return null;
        }
        $issuer = trim((string) $issuerNodes->item(0)->textContent);
        if ($issuer === '') {
            return null;
        }

        $nameIdNodes = $xpath->query('./saml:NameID', $root);
        $nameId = ($nameIdNodes !== false && $nameIdNodes->length === 1)
            ? trim((string) $nameIdNodes->item(0)->textContent)
            : null;

        $sessionIndexNodes = $xpath->query('./samlp:SessionIndex', $root);
        $sessionIndex = ($sessionIndexNodes !== false && $sessionIndexNodes->length >= 1)
            ? trim((string) $sessionIndexNodes->item(0)->textContent)
            : null;

        return [
            'id'           => $id,
            'issueInstant' => $issueInstant,
            'issuer'       => $issuer,
            'destination'  => $root->getAttribute('Destination') !== '' ? $root->getAttribute('Destination') : null,
            'nameId'       => $nameId !== '' ? $nameId : null,
            'sessionIndex' => $sessionIndex !== '' ? $sessionIndex : null,
        ];
    }

    // ========================================================================
    // 🛡️ VALIDATION — the fatal-vs-safe contract
    // ========================================================================

    /**
     * 🛡️ Validate a parsed LogoutRequest end to end.
     *
     * @param array<string,mixed> $parsed         The result of {@see self::parseLogoutRequest()}.
     * @param string              $rawQueryString The raw `$_SERVER['QUERY_STRING']` (Redirect binding — v1's only supported inbound LogoutRequest transport).
     * @return array{
     *     ok: bool, fatal: bool, error: ?string, errorDescription: ?string,
     *     sp: ?array, sloUrl: ?string, requestID: ?string, nameId: ?string, sessionIndex: ?string
     * }
     */
    public static function validateLogoutRequest(array $parsed, string $rawQueryString): array
    {
        // ------------------------------------------------------------------
        // 1️⃣ Issuer -> active SP — FATAL if unresolved/inactive.
        // ------------------------------------------------------------------
        $sp = SamlServiceProviderManager::getByEntityID((string) $parsed['issuer']);
        if ($sp === null || empty($sp['isActive'])) {
            return self::fatalError('unknown_sp', 'Unknown or inactive SAML Service Provider.');
        }

        // ------------------------------------------------------------------
        // 2️⃣ The SP must have a registered SLO endpoint to ever respond to
        //    — FATAL if not (no proven destination for anything past here).
        // ------------------------------------------------------------------
        $sloUrl = (string) ($sp['sloURL'] ?? '');
        if ($sloUrl === '') {
            return self::fatalError('no_slo_endpoint', 'This SP has no registered SingleLogoutService endpoint.');
        }

        // 🎯 From here on, $sloUrl is PROVEN (it is the SP's own registered
        //    value, read from our database — not attacker-supplied) — safe
        //    to send a signed LogoutResponse error there.

        // ------------------------------------------------------------------
        // 3️⃣ Signature policy — identical rules to
        //    SamlAuthnRequestService::validateAuthnRequest() (C1 only in
        //    v1; the "volunteered signature" rule still applies).
        // ------------------------------------------------------------------
        $globalFloor = filter_var(getSetting('saml.require_signed_authnrequests', false), FILTER_VALIDATE_BOOLEAN);
        $wantSigned  = $globalFloor || !empty($sp['wantAuthnRequestsSigned']);
        $volunteered = $rawQueryString !== '' && SamlRedirectBinding::hasSignatureParams($rawQueryString);

        if ($wantSigned || $volunteered) {
            $spCert = (string) ($sp['spSigningCertPEM'] ?? '');
            if ($spCert === '') {
                return self::safeError($sp, $sloUrl, $parsed, 'signature_required', 'A request signature is required or present, but this SP has no registered signing certificate.');
            }
            if (!$volunteered) {
                return self::safeError($sp, $sloUrl, $parsed, 'signature_required', 'This SP requires signed LogoutRequests.');
            }
            if (!SamlRedirectBinding::verifyDetachedSignature($rawQueryString, 'SAMLRequest', $spCert)) {
                return self::safeError($sp, $sloUrl, $parsed, 'invalid_signature', 'LogoutRequest signature verification failed.');
            }
        }

        return [
            'ok'               => true,
            'fatal'            => false,
            'error'            => null,
            'errorDescription' => null,
            'sp'               => $sp,
            'sloUrl'           => $sloUrl,
            'requestID'        => (string) $parsed['id'],
            'nameId'           => $parsed['nameId'] ?? null,
            'sessionIndex'     => $parsed['sessionIndex'] ?? null,
        ];
    }

    // ========================================================================
    // 🏗️ LOGOUT RESPONSE
    // ========================================================================

    /**
     * 🏗️ Build a `<samlp:LogoutResponse>` XML document (the message body —
     * NOT XML-signed; C1's detached signature over the Redirect-binding
     * query string is what protects this message, applied by the caller
     * via {@see SamlRedirectBinding::buildRedirectUrl()}).
     *
     * @param string      $inResponseTo    The consumed LogoutRequest's `ID`.
     * @param string      $statusCodeValue A `self::STATUS_*` URN.
     * @param string|null $destination     The SP's SLO endpoint (set as `Destination`), or null to omit.
     * @return string Serialized XML.
     */
    public static function buildLogoutResponseXml(string $inResponseTo, string $statusCodeValue, ?string $destination = null): string
    {
        $idpEntityId = (string) getSetting('saml.idp_entity_id', 'https://signula.id/saml/metadata');
        $now         = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $responseId  = '_' . bin2hex(random_bytes(self::ID_BYTES));

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $response = $dom->createElementNS(self::SAMLP_NS, 'samlp:LogoutResponse');
        $response->setAttribute('ID', $responseId);
        $response->setAttribute('Version', '2.0');
        $response->setAttribute('IssueInstant', $now->format('Y-m-d\TH:i:s\Z'));
        if ($destination !== null && $destination !== '') {
            $response->setAttribute('Destination', $destination);
        }
        $response->setAttribute('InResponseTo', $inResponseTo);
        $dom->appendChild($response);

        $response->appendChild($dom->createElementNS(self::SAML_NS, 'saml:Issuer', $idpEntityId));

        $status = $dom->createElementNS(self::SAMLP_NS, 'samlp:Status');
        $response->appendChild($status);
        $statusCode = $dom->createElementNS(self::SAMLP_NS, 'samlp:StatusCode');
        $statusCode->setAttribute('Value', $statusCodeValue);
        $status->appendChild($statusCode);

        return $dom->saveXML();
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS
    // ========================================================================

    /**
     * ❌ FATAL result — render a local error page, never respond anywhere.
     *
     * @param string $error
     * @param string $description
     * @return array
     */
    private static function fatalError(string $error, string $description): array
    {
        return [
            'ok' => false, 'fatal' => true, 'error' => $error, 'errorDescription' => $description,
            'sp' => null, 'sloUrl' => null, 'requestID' => null, 'nameId' => null, 'sessionIndex' => null,
        ];
    }

    /**
     * ❌ SAFE (non-fatal) result — the caller may build+sign a
     * `<samlp:LogoutResponse>` error and deliver it to the PROVEN `sloUrl`.
     *
     * @param array  $sp
     * @param string $sloUrl
     * @param array  $parsed
     * @param string $error
     * @param string $description
     * @return array
     */
    private static function safeError(array $sp, string $sloUrl, array $parsed, string $error, string $description): array
    {
        return [
            'ok' => false, 'fatal' => false, 'error' => $error, 'errorDescription' => $description,
            'sp' => $sp, 'sloUrl' => $sloUrl,
            'requestID' => (string) ($parsed['id'] ?? ''),
            'nameId' => $parsed['nameId'] ?? null,
            'sessionIndex' => $parsed['sessionIndex'] ?? null,
        ];
    }
}

// ✅ SamlLogoutService loaded successfully
