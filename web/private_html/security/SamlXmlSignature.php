<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🔏 SIGNula - SAML XML-DSig Facade (G-001 Phase B, #100)
 * ============================================================================
 *
 * Purpose:
 *   The SINGLE project-owned wrapper over the vendored xmlseclibs library
 *   (web/_lib/xmlseclibs/, loaded via {@see SamlXmlLibLoader}) — exactly as
 *   {@see Jwt} is the only surface over `Firebase\JWT\*`. NOTHING else in
 *   SIGNula references `RobRichards\XMLSecLibs\*` directly, so the library
 *   can be swapped (e.g. for the actively-maintained
 *   `simplesamlphp/xml-security` fork, should xmlseclibs' upstream ever go
 *   fully unmaintained) without touching any caller.
 *
 * 🔒 Algorithm allowlist-of-ONE (no caller-chosen algorithms, ever):
 *     CanonicalizationMethod : http://www.w3.org/2001/10/xml-exc-c14n#            (Exclusive C14N, no comments)
 *     SignatureMethod        : http://www.w3.org/2001/04/xmldsig-more#rsa-sha256 (RSA-SHA256)
 *     Reference DigestMethod : http://www.w3.org/2001/04/xmlenc#sha256           (SHA-256)
 *     Reference Transform    : http://www.w3.org/2000/09/xmldsig#enveloped-signature (+ the C14N above)
 *   SHA-1/DSA/HMAC/XSLT/XPath-transform signatures are architecturally
 *   impossible to *produce* through this facade (sign side), and are
 *   explicitly REJECTED before {@see self::verifyEnvelopedSignature()} ever
 *   calls into xmlseclibs' own verify() (verify side) — see
 *   {@see self::signatureUsesAllowedAlgorithms()}.
 *
 * 🚦 DORMANT-FOUNDATION SCOPE (issue #100 plan §3.4/§8 — read before changing
 *   how this class is CALLED, not how it is written):
 *   - {@see self::signAssertion()} / {@see self::signResponse()} (C2 / "B1" in
 *     the plan) are SAFE to build now: we control the input document
 *     entirely, so a sign-side bug is an interop failure (an SP rejects a
 *     login), never a forgery. They are wired into SamlResponseBuilder but
 *     the whole IdP surface stays behind `saml.enabled` (default OFF).
 *   - {@see self::verifyEnvelopedSignature()} (C3 / "B2" in the plan) is
 *     implemented here as a COMPLETE, hardened primitive — including the
 *     §9 XSW (XML Signature Wrapping) defences below — but as of this
 *     dormant-foundation delivery it is **NOT CALLED by any engine or
 *     controller**. Verifying a POST-bound, attacker-supplied, SIGNED
 *     AuthnRequest is exactly the scenario that broke SimpleSAMLphp,
 *     python-saml, ruby-saml, and OneLogin in the 2012-2018 XSW research
 *     wave (Somorovsky et al., "On Breaking SAML: Be Whoever You Want to
 *     Be") and is explicitly gated on its OWN staged red-team pass (plan
 *     §8 Stage S6 / §10 Gate 3) before `wantAuthnRequestsSigned=1` is ever
 *     honoured over the POST binding. v1 policy only verifies inbound
 *     signatures via the HTTP-Redirect binding's DETACHED signature scheme
 *     ({@see SamlRedirectBinding}), which is a completely different, much
 *     simpler primitive (raw openssl_verify over a query string — NOT
 *     XML-DSig, NOT attacker-controlled-XML-parsing) and carries none of
 *     XSW's risk.
 *
 * 🛡️ XSW (XML Signature Wrapping) defences baked into
 *   {@see self::verifyEnvelopedSignature()} (plan §9 item 5):
 *     1. Exactly ONE <ds:Signature> as a DIRECT CHILD of the element being
 *        verified (reject zero, reject more).
 *     2. Exactly ONE <ds:Signature> ANYWHERE in the owning document (reject
 *        a second signature planted elsewhere in the tree — the classic
 *        "wrap the real element in an attacker-controlled clone" gadget).
 *     3. The <Reference URI="#..."> MUST resolve, by our OWN attribute
 *        lookup (never `DOMDocument::getElementById()` / DTD ID-typing,
 *        which this facade's hardened XML intake rejects DTDs for anyway),
 *        to the EXACT element passed in as `$signedElement` — never trust
 *        xmlseclibs' internal reference resolution alone.
 *     4. After verification, callers must re-extract every consumed value
 *        (Issuer, NameID, conditions, …) from `$signedElement` ONLY — never
 *        from a second, unverified copy elsewhere in the document. This
 *        facade cannot enforce that part (it is a caller discipline), so it
 *        is called out here for the caller's benefit.
 *     5. Any `<ds:KeyInfo>`/`<ds:X509Certificate>` embedded in the message
 *        is IGNORED — the pinned, DB-registered SP certificate passed in by
 *        the caller is the ONLY key source, ever.
 *     6. Algorithm allowlist-of-one, enforced BEFORE calling verify() (see
 *        above) — verify() itself does not enforce any allowlist and would
 *        happily validate a SHA-1/HMAC signature if asked.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-dom, ext-openssl.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://www.w3.org/TR/xmldsig-core1/ (XML Signature Syntax and Processing, Version 1.1)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf (SAML 2.0 Core — §5 Signatures)
 * @link       https://www.usenix.org/conference/usenixsecurity12/technical-sessions/presentation/somorovsky (XSW research)
 *
 * @see web/private_html/security/SamlXmlLibLoader.php (vendored-library loader)
 * @see web/private_html/security/Jwt.php               (the sibling facade this class's shape mirrors)
 * @see web/private_html/auth/SamlResponseBuilder.php    (the only current caller of the sign* methods)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class SamlXmlSignature
{
    /** @var string XML-DSig namespace URI (used for our own DOM/XPath queries — never delegated to the vendored lib for the security-critical counting checks). */
    private const XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    /** @var string Exclusive XML Canonicalization (no comments) — the ONLY canonicalization method this facade will ever sign with or accept on verify. */
    private const CANONICAL_METHOD_URI = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    /** @var string RSA-SHA256 — the ONLY SignatureMethod this facade will ever sign with or accept on verify. */
    private const SIGNATURE_METHOD_URI = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    /** @var string SHA-256 — the ONLY Reference DigestMethod this facade will ever sign with or accept on verify. */
    private const DIGEST_METHOD_URI = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /** @var string The enveloped-signature transform — MUST be present on every Reference this facade produces or accepts. */
    private const ENVELOPED_TRANSFORM_URI = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    // ========================================================================
    // ✍️ SIGNING (C2 / plan "B1" — safe to build now; see class header)
    // ========================================================================

    /**
     * ✍️ Sign a `<saml:Assertion>` element in place (enveloped XML-DSig).
     *
     * Per SAML 2.0 Core §5.4.2 / this codebase's schema-order convention, the
     * resulting `<ds:Signature>` is inserted as the sibling IMMEDIATELY
     * BEFORE `$subjectNode` — i.e. right after `<saml:Issuer>` and before
     * `<saml:Subject>`, which is the position every SAML SP implementation
     * expects.
     *
     * @param DOMElement $assertionNode The `<saml:Assertion>` element. MUST
     *                                  already carry a non-empty `ID`
     *                                  attribute (SamlResponseBuilder sets
     *                                  this before calling in) — the
     *                                  Reference URI is built from it.
     * @param DOMElement $subjectNode   The `<saml:Subject>` element already
     *                                  present as a child of $assertionNode
     *                                  — the signature is inserted directly
     *                                  before it.
     * @param string     $privateKeyPem SIGNula's SAML signing PRIVATE key PEM
     *                                  (from {@see SamlKeyManager::getActiveKey()}).
     * @param string     $certPem       The matching X.509 certificate PEM —
     *                                  embedded in `<ds:KeyInfo><ds:X509Data>`
     *                                  so the SP can verify without a
     *                                  separate metadata fetch.
     * @return void
     * @throws \RuntimeException If xmlseclibs is not vendored/loadable.
     * @throws \InvalidArgumentException If $assertionNode has no ID attribute.
     * @throws \Exception Propagated from xmlseclibs on a malformed key/cert.
     */
    public static function signAssertion(
        DOMElement $assertionNode,
        DOMElement $subjectNode,
        string $privateKeyPem,
        string $certPem
    ): void {
        self::signEnvelopedElement($assertionNode, $subjectNode, $privateKeyPem, $certPem);
    }

    /**
     * ✍️ Sign a `<samlp:Response>` element in place (enveloped XML-DSig).
     *
     * Some SPs (e.g. AWS IAM Identity Center-style integrations) require the
     * `<Response>` itself signed IN ADDITION TO the `<Assertion>` — per the
     * plan's §6.2 ordering rule, **sign the Assertion FIRST, then the
     * Response** (the Response's signature envelopes the already-signed
     * Assertion), so this must always be called AFTER
     * {@see self::signAssertion()} when both are required.
     *
     * The `<ds:Signature>` is inserted immediately before `$statusNode` —
     * i.e. right after `<saml:Issuer>` and before `<samlp:Status>`, per
     * SAML 2.0 Core §5.4.2's schema-order convention for `<Response>`.
     *
     * @param DOMElement $responseNode  The `<samlp:Response>` element. MUST
     *                                  already carry a non-empty `ID`.
     * @param DOMElement $statusNode    The `<samlp:Status>` element already
     *                                  present as a child of $responseNode.
     * @param string     $privateKeyPem SIGNula's SAML signing PRIVATE key PEM.
     * @param string     $certPem       The matching X.509 certificate PEM.
     * @return void
     * @throws \RuntimeException If xmlseclibs is not vendored/loadable.
     * @throws \InvalidArgumentException If $responseNode has no ID attribute.
     */
    public static function signResponse(
        DOMElement $responseNode,
        DOMElement $statusNode,
        string $privateKeyPem,
        string $certPem
    ): void {
        self::signEnvelopedElement($responseNode, $statusNode, $privateKeyPem, $certPem);
    }

    /**
     * ✍️ Shared enveloped-XML-DSig signing routine (plan §6.2's exact template).
     *
     * @param DOMElement $containerNode   The element to sign (Assertion or Response) — MUST have a non-empty `ID` attribute already set.
     * @param DOMElement $insertBeforeNode The child of $containerNode the `<ds:Signature>` is inserted directly before (schema-position discipline).
     * @param string     $privateKeyPem   Private key PEM.
     * @param string     $certPem         X.509 certificate PEM.
     * @return void
     */
    private static function signEnvelopedElement(
        DOMElement $containerNode,
        DOMElement $insertBeforeNode,
        string $privateKeyPem,
        string $certPem
    ): void {
        self::ensureLibLoaded();

        // 🛡️ The Reference URI is built from this ID — refuse to sign an
        //    element that has none rather than let xmlseclibs silently mint
        //    one via generateGUID() (we want the SAME ID that appears
        //    elsewhere in the document, e.g. as the assertion's own
        //    tblSAMLAssertions.samlAssertionID, to be the one that's signed).
        $id = $containerNode->getAttribute('ID');
        if ($id === '') {
            throw new \InvalidArgumentException(
                'SamlXmlSignature::signEnvelopedElement — the element to sign must already carry a non-empty ID attribute.'
            );
        }

        $objDSig = new \RobRichards\XMLSecLibs\XMLSecurityDSig();
        $objDSig->setCanonicalMethod(self::CANONICAL_METHOD_URI);

        // 🔗 Reference the container by its EXISTING id (overwrite=false —
        //    never replace the ID we deliberately set with a fresh GUID).
        $objDSig->addReferenceList(
            [$containerNode],
            self::DIGEST_METHOD_URI,
            [self::ENVELOPED_TRANSFORM_URI, self::CANONICAL_METHOD_URI],
            ['id_name' => 'ID', 'overwrite' => false]
        );

        $objKey = new \RobRichards\XMLSecLibs\XMLSecurityKey(
            \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
            ['type' => 'private']
        );
        $objKey->loadKey($privateKeyPem, false);

        // ✍️ Compute SignedInfo/SignatureValue on the library's own detached
        //    template node FIRST, then import+attach it via insertSignature()
        //    — the standard xmlseclibs two-step (matches the plan's exact
        //    call sequence in §6.2).
        $objDSig->sign($objKey);
        $objDSig->add509Cert($certPem);
        $objDSig->insertSignature($containerNode, $insertBeforeNode);
    }

    // ========================================================================
    // 🔍 VERIFICATION (C3 / plan "B2" — implemented, but NOT wired into any
    //    live call path in this dormant-foundation delivery; see class header)
    // ========================================================================

    /**
     * 🔍 Verify an enveloped XML-DSig `<ds:Signature>` on `$signedElement`
     * against a PINNED (registered, out-of-band) certificate.
     *
     * ⚠️ NOT CALLED by any engine/controller as of this delivery — see the
     * class header's "DORMANT-FOUNDATION SCOPE" section. Provided complete
     * and hardened (§9 XSW defences) so the dedicated, red-teamed S6 stage
     * (plan §8/§10 Gate 3) can wire it up without first having to write it.
     *
     * ⚠️ SIDE EFFECT: xmlseclibs' `validateReference()` temporarily DETACHES
     * the located `<ds:Signature>` node from the DOM to correctly recompute
     * the enveloped-signature transform (this is standard/required xmlseclibs
     * behaviour, not a bug here) — callers must not rely on `$signedElement`'s
     * owning document being unmodified after this call returns (regardless of
     * the boolean result).
     *
     * @param DOMElement $signedElement The element the signature covers
     *                                  (e.g. the `<samlp:AuthnRequest>` root
     *                                  — SAML 2.0 Core requires the signed
     *                                  element to BE the document element for
     *                                  POST-bound signed requests; callers
     *                                  MUST enforce that separately per plan
     *                                  §9 item 5, this method only verifies
     *                                  the signature it is given).
     * @param string $pinnedCertPem The SP's REGISTERED X.509 certificate PEM
     *                              (`tblSAMLServiceProviders.spSigningCertPEM`)
     *                              — any `<ds:KeyInfo>` in the message itself
     *                              is deliberately never consulted.
     * @return bool True ONLY when every XSW guard passes AND xmlseclibs'
     *              `verify()` returns exactly `1` (never truthy-cast a `-1`
     *              error return, per the WebAuthnHandler `=== 1` discipline).
     * @throws \RuntimeException If xmlseclibs is not vendored/loadable.
     */
    public static function verifyEnvelopedSignature(DOMElement $signedElement, string $pinnedCertPem): bool
    {
        self::ensureLibLoaded();

        if ($pinnedCertPem === '') {
            return false;
        }

        // ------------------------------------------------------------------
        // 🛡️ XSW guard 1 — exactly ONE <ds:Signature> as a DIRECT CHILD of
        //    $signedElement (reject zero, reject more than one).
        // ------------------------------------------------------------------
        $directSignatures = [];
        foreach ($signedElement->childNodes as $child) {
            if ($child instanceof DOMElement
                && $child->localName === 'Signature'
                && $child->namespaceURI === self::XMLDSIG_NS) {
                $directSignatures[] = $child;
            }
        }
        if (count($directSignatures) !== 1) {
            return false;
        }
        $sigNode = $directSignatures[0];

        // ------------------------------------------------------------------
        // 🛡️ XSW guard 2 — exactly ONE <ds:Signature> ANYWHERE in the owning
        //    document (reject a second signature planted elsewhere in the
        //    tree — the classic "wrapper" gadget).
        // ------------------------------------------------------------------
        $doc = $signedElement->ownerDocument;
        if ($doc === null) {
            return false;
        }
        $docXPath = new DOMXPath($doc);
        $docXPath->registerNamespace('ds', self::XMLDSIG_NS);
        $allSignatures = $docXPath->query('//ds:Signature');
        if ($allSignatures === false || $allSignatures->length !== 1) {
            return false;
        }

        // ------------------------------------------------------------------
        // 🛡️ XSW guard 3 — algorithm allowlist-of-one, enforced BEFORE
        //    xmlseclibs ever sees the signature (verify() itself enforces
        //    nothing — it will happily validate SHA-1/HMAC if asked).
        // ------------------------------------------------------------------
        if (!self::signatureUsesAllowedAlgorithms($sigNode)) {
            return false;
        }

        // ------------------------------------------------------------------
        // 🛡️ XSW guard 4 — the <Reference URI="#..."> MUST resolve, by OUR
        //    OWN attribute lookup, to the EXACT element passed in — never
        //    trust the library's internal resolution alone.
        // ------------------------------------------------------------------
        if (!self::referenceUriMatchesElement($sigNode, $signedElement)) {
            return false;
        }

        // ------------------------------------------------------------------
        // ✅ All structural/allowlist guards passed — now (and only now) hand
        //    off to xmlseclibs for the actual cryptographic verification,
        //    using ONLY the pinned certificate (any embedded KeyInfo/
        //    X509Certificate in the message is never consulted — XSW guard 5).
        // ------------------------------------------------------------------
        try {
            $objDSig = new \RobRichards\XMLSecLibs\XMLSecurityDSig();

            // 🔑 xmlseclibs' OWN Reference-resolution XPath (processRefNode())
            //    hardcodes the attribute name "Id" (mixed case) unless extra
            //    names are registered via the public $idKeys property — SAML
            //    always uses "ID" (all-caps, per the XSD), so without this the
            //    digest lookup silently resolves to nothing and
            //    validateReference() below always throws. This does NOT
            //    replace XSW guard 4 (referenceUriMatchesElement) — that guard
            //    additionally rejects a DOCUMENT with more than one element
            //    sharing the same ID value, which xmlseclibs' own
            //    `//*[@ID="..."]` lookup (first-match, document order) would
            //    not otherwise catch.
            $objDSig->idKeys = ['ID'];

            $foundSigNode = $objDSig->locateSignature($signedElement);
            if ($foundSigNode === null || !$foundSigNode->isSameNode($sigNode)) {
                // Should be unreachable given guards 1-2 above, but never
                // trust a second code path's resolution to agree implicitly.
                return false;
            }

            $objDSig->canonicalizeSignedInfo();
            $objDSig->validateReference(); // throws on digest mismatch

            $objKey = new \RobRichards\XMLSecLibs\XMLSecurityKey(
                \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
                ['type' => 'public']
            );
            $objKey->loadKey($pinnedCertPem, false, true); // isCert=true -> extract pubkey from the PEM cert

            $result = $objDSig->verify($objKey);
        } catch (\Throwable $e) {
            // 🔇 Any parse/digest/verify exception is a verification FAILURE,
            //    never an error the caller should treat differently — fail closed.
            return false;
        }

        // openssl/xmlseclibs convention: 1 = valid, 0 = invalid, -1 = error.
        // PHP casts -1 to `true` in a loose boolean context — NEVER `if ($result)`.
        return $result === 1;
    }

    /**
     * 🛡️ XSW guard 3's implementation — every algorithm the signature
     * declares must be EXACTLY the one this facade allows; nothing is
     * negotiable at verify time.
     *
     * @param DOMElement $sigNode The (already uniquely-located) `<ds:Signature>` element.
     * @return bool
     */
    private static function signatureUsesAllowedAlgorithms(DOMElement $sigNode): bool
    {
        $doc = $sigNode->ownerDocument;
        if ($doc === null) {
            return false;
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);

        $canonMethod = $xpath->evaluate('string(./ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm)', $sigNode);
        if ($canonMethod !== self::CANONICAL_METHOD_URI) {
            return false;
        }

        $sigMethod = $xpath->evaluate('string(./ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $sigNode);
        if ($sigMethod !== self::SIGNATURE_METHOD_URI) {
            // 🚫 Rejects rsa-sha1, hmac-sha1, dsa-sha1, and every other
            //    SignatureMethod URI outright — allowlist of exactly one.
            return false;
        }

        // 🛡️ Exactly ONE <ds:Reference> — extra references are themselves an
        //    XSW/DoS smell (a verifier that only checks the FIRST reference
        //    while a second, unchecked one carries the attacker's payload).
        $referenceNodes = $xpath->query('./ds:SignedInfo/ds:Reference', $sigNode);
        if ($referenceNodes === false || $referenceNodes->length !== 1) {
            return false;
        }
        $refNode = $referenceNodes->item(0);
        if (!($refNode instanceof DOMElement)) {
            return false;
        }

        $digestMethod = $xpath->evaluate('string(./ds:DigestMethod/@Algorithm)', $refNode);
        if ($digestMethod !== self::DIGEST_METHOD_URI) {
            return false;
        }

        // 🚫 Only the enveloped-signature transform + our one canonicalization
        //    method are permitted as Reference transforms — explicitly
        //    rejects XSLT/XPath-filter transforms (a documented XSW/XXE
        //    gadget class) and any other transform URI.
        $transformAlgorithms = [];
        foreach ($xpath->query('./ds:Transforms/ds:Transform', $refNode) as $transformNode) {
            if ($transformNode instanceof DOMElement) {
                $transformAlgorithms[] = $transformNode->getAttribute('Algorithm');
            }
        }
        $allowedTransforms = [self::ENVELOPED_TRANSFORM_URI, self::CANONICAL_METHOD_URI];
        foreach ($transformAlgorithms as $alg) {
            if (!in_array($alg, $allowedTransforms, true)) {
                return false;
            }
        }
        if (!in_array(self::ENVELOPED_TRANSFORM_URI, $transformAlgorithms, true)) {
            // The enveloped-signature transform MUST be present — otherwise
            // the digest covers a copy of the element that still CONTAINS
            // the Signature itself, which is not what we ever produce/expect.
            return false;
        }

        return true;
    }

    /**
     * 🛡️ XSW guard 4's implementation — resolve `<Reference URI="#id">` via
     * a plain attribute read (never `getElementById()` / DTD ID-typing —
     * this facade's hardened XML intake rejects DTDs outright anyway, see
     * SamlAuthnRequestService::loadDom()) and confirm it names EXACTLY the
     * element the caller asked us to verify.
     *
     * @param DOMElement $sigNode       The `<ds:Signature>` element.
     * @param DOMElement $signedElement The element the caller believes is signed.
     * @return bool
     */
    private static function referenceUriMatchesElement(DOMElement $sigNode, DOMElement $signedElement): bool
    {
        $doc = $sigNode->ownerDocument;
        if ($doc === null) {
            return false;
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);

        $refNode = $xpath->query('./ds:SignedInfo/ds:Reference', $sigNode)->item(0);
        if (!($refNode instanceof DOMElement)) {
            return false;
        }

        $refUri = $refNode->getAttribute('URI');
        if ($refUri === '' || $refUri[0] !== '#') {
            // 🚫 An empty/whole-document URI ("") or a non-fragment URI is
            //    never acceptable here — we only ever sign/expect a
            //    same-document fragment reference to the element's own ID.
            return false;
        }

        $expectedId = substr($refUri, 1);
        $actualId   = $signedElement->getAttribute('ID');

        if ($expectedId === '' || $actualId === '' || !hash_equals($expectedId, $actualId)) {
            return false;
        }

        // 🛡️ Duplicate-ID XSW guard: reject if ANY OTHER element ANYWHERE in
        //    the document also carries this same ID value under an attribute
        //    name xmlseclibs' own reference-resolution would accept ("ID" —
        //    registered via $idKeys above — or its hardcoded default "Id").
        //    Without this, a document with two elements sharing one ID lets
        //    an attacker relocate the genuinely-signed element deep in the
        //    tree (still resolvable by ID) while presenting a DIFFERENT,
        //    attacker-controlled element with the same ID value as
        //    `$signedElement` to the caller — classic XSW "ID confusion".
        $docXPath = new DOMXPath($doc);
        $literal = self::xpathStringLiteral($expectedId);
        $idMatches = $docXPath->query('//*[@ID=' . $literal . ' or @Id=' . $literal . ']');
        if ($idMatches === false || $idMatches->length !== 1 || !$idMatches->item(0)->isSameNode($signedElement)) {
            return false;
        }

        return true;
    }

    /**
     * 🔧 XPath 1.0 has no string-escaping mechanism — build a safe string
     * literal via the standard `concat()` trick so an ID value containing a
     * `'` or `"` can never break out of the XPath expression it is
     * interpolated into (defence-in-depth; SAML IDs are normally a fixed
     * `_` + hex shape, but this input is attacker-echoed in a verify path).
     *
     * @param string $value Raw string to embed as an XPath literal.
     * @return string A complete XPath expression evaluating to $value.
     */
    private static function xpathStringLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        // Contains BOTH quote characters — split on ' and re-join with a
        // concat() call that expresses the literal single-quote via "'".
        $parts = explode("'", $value);
        $quoted = array_map(static fn(string $p): string => "'" . $p . "'", $parts);
        return 'concat(' . implode(', "\'", ', $quoted) . ')';
    }

    // ========================================================================
    // 🔧 INTERNAL
    // ========================================================================

    /**
     * 🔧 Ensure the vendored xmlseclibs library is present and loaded.
     *
     * @return void
     * @throws \RuntimeException With a clear remediation pointer if the
     *                           vendored files are missing/corrupt.
     */
    private static function ensureLibLoaded(): void
    {
        if (!class_exists('SamlXmlLibLoader') || !SamlXmlLibLoader::isAvailable()) {
            throw new \RuntimeException(
                'SamlXmlSignature: xmlseclibs is not vendored under web/_lib/xmlseclibs/src/ — '
                . 'see web/_lib/xmlseclibs/VERSION for the exact pinned release '
                . '(robrichards/xmlseclibs 3.1.5, BSD-3-Clause) and the 4 files to restore '
                . '(XMLSecurityKey.php, XMLSecurityDSig.php, XMLSecEnc.php, Utils/XPath.php) '
                . 'before any XML-DSig sign/verify can run. This is expected to never fire in a '
                . 'normal checkout of this repository.'
            );
        }

        SamlXmlLibLoader::load();
    }
}

// ✅ SamlXmlSignature loaded successfully
