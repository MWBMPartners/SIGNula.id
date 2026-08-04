<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - SAML 2.0 Identity-Provider: AuthnRequest Intake + Validation
 * ============================================================================
 *
 * Purpose:
 *   The pure/DB-bound engine behind `GET/POST /saml/sso` (SP-initiated SSO —
 *   G-001 Phase B, #100): hardened XML intake for BOTH bindings (§6.4),
 *   `<samlp:AuthnRequest>` parsing, the fatal-vs-safe validation contract
 *   (mirrors {@see OAuthAuthorizeService::validateAuthorizeRequest()}), and
 *   the single-use pending-request store (`tblSAMLAuthnRequests`) that lets
 *   a request survive the `/login?redirect=…` + MFA round-trip without ever
 *   trusting client-side state for anything security-relevant.
 *
 * 🛡️ The fatal-vs-safe discriminated-union contract (the SAME ordering
 *   discipline as OAuthAuthorizeService, transplanted to SAML):
 *   - `fatal === true`  → render a LOCAL, non-redirectable error page. No
 *     proven-safe ACS destination exists yet (unknown/inactive SP, or an
 *     AssertionConsumerServiceURL that failed the exact-match allowlist) —
 *     responding ANYWHERE would be an open/covert-redirect vector.
 *   - `fatal === false` (and `ok === false`) → the ACS URL IS proven safe;
 *     the controller may build a SIGNED SAML error `<Response>`
 *     (`urn:oasis:names:tc:SAML:2.0:status:Requester`) via
 *     {@see SamlResponseBuilder} and deliver it there.
 *   - `ok === true` → every field needed to proceed to login/MFA/consent is
 *     populated.
 *
 * 🛡️ Signature-verification policy (plan §6.3/§8 — the risk-asymmetry split):
 *   - HTTP-Redirect binding (C1): a detached signature is ALWAYS verified
 *     via {@see SamlRedirectBinding} when present (even if not required —
 *     the "volunteered signature" rule) or when policy requires it.
 *   - HTTP-POST binding + `wantAuthnRequestsSigned=1`: **refused** in this
 *     dormant-foundation delivery — POST-binding XML-DSig verification (C3)
 *     is its own dedicated, mandatory-red-team stage (plan §8 Stage S6 /
 *     §10 Gate 3) and is NOT wired here. An SP that both POSTs its
 *     AuthnRequest AND demands signed requests gets a clear, safe error
 *     rather than a silently-unverified signature.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-dom, ext-zlib.
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf (SAML 2.0 Core — §3.4 Authentication Request Protocol)
 * @link       https://owasp.org/www-community/vulnerabilities/XML_External_Entity_(XXE)_Processing
 *
 * @see web/private_html/auth/OAuthAuthorizeService.php   (the fatal/redirectable contract this mirrors)
 * @see web/private_html/security/SamlRedirectBinding.php (C1 detached-signature verify)
 * @see web/private_html/auth/SamlServiceProviderManager.php (SP + ACS resolution)
 * @see web/public_html/saml/sso.php (the thin controller that consumes this class)
 * @see _database/migrations/050_saml_idp.sql (tblSAMLAuthnRequests)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class SamlAuthnRequestService
{
    /** @var string SAML protocol namespace (samlp:). */
    private const SAMLP_NS = 'urn:oasis:names:tc:SAML:2.0:protocol';

    /** @var string SAML assertion namespace (saml:). */
    private const SAML_NS = 'urn:oasis:names:tc:SAML:2.0:assertion';

    /** @var int Random bytes for the pending-request resume handle — 32 bytes = 256-bit, mirrors OAuth authorization-code entropy. */
    private const HANDLE_BYTES = 32;

    // ========================================================================
    // 🧼 HARDENED XML INTAKE (plan §6.4 — every inbound parse, request or metadata import)
    // ========================================================================

    /**
     * 🧼 Load an XML string into a hardened `DOMDocument`.
     *
     * Guards, in order:
     *   1. Non-empty + a hard byte-size ceiling (`saml.max_inflated_request_bytes`)
     *      — the caller's DEFLATE-inflate step (Redirect binding) or raw
     *      base64-decode step (POST binding) already capped the SAME
     *      setting, but this re-checks defensively in case `loadDom()` is
     *      ever called on a string from a different source.
     *   2. `libxml_use_internal_errors(true)` + `loadXML(..., LIBXML_NONET
     *      | LIBXML_NSCLEAN)` — never resolve external resources over the
     *      network; PHP ≥8.0/libxml2 ≥2.9 already disable external entity
     *      substitution by default (the legacy
     *      `libxml_disable_entity_loader()` toggle is a no-op/deprecated on
     *      this runtime), so this is confirming the safe default, not
     *      establishing it.
     *   3. Reject ANY document carrying a `DOCTYPE` declaration outright,
     *      regardless of content — XXE/DTD belt-and-braces defence-in-depth
     *      on top of #2.
     *
     * @param string $xml Candidate XML.
     * @return DOMDocument|null The loaded document, or null on ANY failure
     *                          (oversize, malformed XML, or a DOCTYPE present).
     */
    public static function loadDom(string $xml): ?DOMDocument
    {
        if ($xml === '') {
            return null;
        }

        $maxBytes = (int) getSetting('saml.max_inflated_request_bytes', 1048576);
        if (strlen($xml) > max(1, $maxBytes)) {
            return null;
        }

        $priorSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // @-suppressed: malformed XML is EXPECTED adversarial input here —
        // libxml_use_internal_errors() above is what actually captures it;
        // we only care about the boolean/DOMDocument result.
        $dom = new DOMDocument();
        $loaded = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NSCLEAN);

        libxml_clear_errors();
        libxml_use_internal_errors($priorSetting);

        if ($loaded === false) {
            return null;
        }

        // 🛡️ XXE/DTD defence-in-depth — reject a DOCTYPE node outright.
        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_DOCUMENT_TYPE_NODE) {
                return null;
            }
        }

        return $dom->documentElement !== null ? $dom : null;
    }

    /**
     * 📦 Decode an HTTP-Redirect-binding `SAMLRequest` value (base64 + raw
     * DEFLATE, with the DEFLATE-bomb byte cap) into an XML string ready for
     * {@see self::loadDom()}.
     *
     * @param string $base64Deflated The raw `SAMLRequest` query parameter (already URL-decoded by PHP's superglobal parsing).
     * @return string|null
     */
    public static function decodeRedirectBindingPayload(string $base64Deflated): ?string
    {
        $maxBytes = (int) getSetting('saml.max_inflated_request_bytes', 1048576);

        return SamlRedirectBinding::decodeMessage($base64Deflated, $maxBytes);
    }

    /**
     * 📦 Decode an HTTP-POST-binding `SAMLRequest` value (base64 only — NO
     * DEFLATE, per SAML Bindings §3.5.4) into an XML string.
     *
     * @param string $base64Xml The raw `SAMLRequest` POST field.
     * @return string|null
     */
    public static function decodePostBindingPayload(string $base64Xml): ?string
    {
        $raw = base64_decode($base64Xml, true);
        if ($raw === false || $raw === '') {
            return null;
        }

        $maxBytes = (int) getSetting('saml.max_inflated_request_bytes', 1048576);
        if (strlen($raw) > max(1, $maxBytes)) {
            return null;
        }

        return $raw;
    }

    // ========================================================================
    // 🔍 PARSING
    // ========================================================================

    /**
     * 🔍 Parse a hardened `<samlp:AuthnRequest>` document into a flat array.
     *
     * Enforces: the document element IS `samlp:AuthnRequest` (correct
     * namespace, no nested/duplicate root), `Version="2.0"`, a non-empty
     * `ID` + `IssueInstant`, and EXACTLY ONE `<saml:Issuer>` child (rejecting
     * zero or multiple — a duplicated Issuer is an XSW-adjacent smuggling
     * smell even before any signature is involved).
     *
     * @param DOMDocument $dom An already-hardened document (see {@see self::loadDom()}).
     * @return array{id:string,issueInstant:string,issuer:string,destination:?string,assertionConsumerServiceURL:?string,forceAuthn:bool,isPassive:bool,requestedNameIDFormat:?string}|null
     *         null if the document does not conform.
     */
    public static function parseAuthnRequest(DOMDocument $dom): ?array
    {
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'AuthnRequest' || $root->namespaceURI !== self::SAMLP_NS) {
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
            return null; // Issuer required; exactly one.
        }
        $issuer = trim((string) $issuerNodes->item(0)->textContent);
        if ($issuer === '') {
            return null;
        }

        $requestedNameIDFormat = null;
        $nameIdPolicyNodes = $xpath->query('./samlp:NameIDPolicy', $root);
        if ($nameIdPolicyNodes !== false && $nameIdPolicyNodes->length === 1) {
            $policyNode = $nameIdPolicyNodes->item(0);
            if ($policyNode instanceof DOMElement && $policyNode->hasAttribute('Format')) {
                $fmt = $policyNode->getAttribute('Format');
                $requestedNameIDFormat = $fmt !== '' ? $fmt : null;
            }
        }

        return [
            'id'                          => $id,
            'issueInstant'                => $issueInstant,
            'issuer'                      => $issuer,
            'destination'                 => $root->getAttribute('Destination') !== '' ? $root->getAttribute('Destination') : null,
            'assertionConsumerServiceURL' => $root->hasAttribute('AssertionConsumerServiceURL') ? $root->getAttribute('AssertionConsumerServiceURL') : null,
            'forceAuthn'                  => filter_var($root->getAttribute('ForceAuthn'), FILTER_VALIDATE_BOOLEAN),
            'isPassive'                   => filter_var($root->getAttribute('IsPassive'), FILTER_VALIDATE_BOOLEAN),
            'requestedNameIDFormat'       => $requestedNameIDFormat,
        ];
    }

    // ========================================================================
    // 🛡️ VALIDATION — the fatal-vs-safe contract
    // ========================================================================

    /**
     * 🛡️ Validate a parsed AuthnRequest end to end.
     *
     * @param array<string,mixed> $parsed The result of {@see self::parseAuthnRequest()}.
     * @param string $binding 'redirect' or 'post' — which binding delivered this request.
     * @param string $rawQueryString For `redirect`, the RAW `$_SERVER['QUERY_STRING']` (used for C1 signature verification); ignored for `post`.
     * @return array{
     *     ok: bool, fatal: bool, error: ?string, errorDescription: ?string,
     *     sp: ?array, acsUrl: ?string, requestID: ?string,
     *     forceAuthn: bool, isPassive: bool, requestedNameIDFormat: ?string
     * }
     */
    public static function validateAuthnRequest(array $parsed, string $binding, string $rawQueryString = ''): array
    {
        // ------------------------------------------------------------------
        // 1️⃣ Issuer -> active SP — FATAL if unresolved/inactive. No proven
        //    ACS exists yet, so we must NEVER respond anywhere.
        // ------------------------------------------------------------------
        $sp = SamlServiceProviderManager::getByEntityID((string) $parsed['issuer']);
        if ($sp === null || empty($sp['isActive'])) {
            return self::fatalError('unknown_sp', 'Unknown or inactive SAML Service Provider.');
        }

        // ------------------------------------------------------------------
        // 2️⃣ ACS resolution + EXACT-MATCH allowlist (the #1 SAML IdP
        //    security control) — FATAL if unproven.
        // ------------------------------------------------------------------
        $acsUrl = SamlServiceProviderManager::resolveAcsUrl($sp, $parsed['assertionConsumerServiceURL'] ?? null);
        if ($acsUrl === null) {
            return self::fatalError('invalid_acs', 'Missing or unregistered AssertionConsumerServiceURL.');
        }

        // 🎯 From here on, $acsUrl is a PROVEN member of this SP's allowlist
        //    — every subsequent failure can safely be reported there via a
        //    SIGNED SAML error Response (SamlResponseBuilder).

        // ------------------------------------------------------------------
        // 3️⃣ IssueInstant freshness (± clock skew, plus a generous outer
        //    bound so a wildly stale/future timestamp is rejected
        //    regardless of the exact configured skew).
        // ------------------------------------------------------------------
        $skew = (int) getSetting('saml.clock_skew', 120);
        $issueTimestamp = self::parseSamlTime((string) $parsed['issueInstant']);
        if ($issueTimestamp === null || abs(time() - $issueTimestamp) > ($skew + 300)) {
            return self::safeError($sp, $acsUrl, $parsed, 'invalid_issue_instant', 'AuthnRequest IssueInstant is outside the acceptable window.');
        }

        // ------------------------------------------------------------------
        // 4️⃣ Signature policy (C1 Redirect-binding verify; C3 POST-binding
        //    verify is a dedicated future stage — see class header).
        // ------------------------------------------------------------------
        $globalFloor = filter_var(getSetting('saml.require_signed_authnrequests', false), FILTER_VALIDATE_BOOLEAN);
        $wantSigned  = $globalFloor || !empty($sp['wantAuthnRequestsSigned']);

        if ($binding === 'redirect') {
            $volunteered = $rawQueryString !== '' && SamlRedirectBinding::hasSignatureParams($rawQueryString);

            if ($wantSigned || $volunteered) {
                $spCert = (string) ($sp['spSigningCertPEM'] ?? '');
                if ($spCert === '') {
                    return self::safeError($sp, $acsUrl, $parsed, 'signature_required', 'A request signature is required or present, but this SP has no registered signing certificate.');
                }
                if (!$volunteered) {
                    return self::safeError($sp, $acsUrl, $parsed, 'signature_required', 'This SP requires signed AuthnRequests.');
                }
                // 🔒 "Volunteered signature" rule (plan §6.3): verify it
                //    regardless of whether it was strictly required — a
                //    present-but-invalid signature is NEVER silently ignored.
                if (!SamlRedirectBinding::verifyDetachedSignature($rawQueryString, 'SAMLRequest', $spCert)) {
                    return self::safeError($sp, $acsUrl, $parsed, 'invalid_signature', 'AuthnRequest signature verification failed.');
                }
            }
        } elseif ($wantSigned) {
            // 🚧 C3 (POST-binding XML-DSig verification) is NOT wired in
            //    this dormant-foundation delivery — see class header.
            return self::safeError(
                $sp,
                $acsUrl,
                $parsed,
                'unsupported_binding',
                'This SP requires signed AuthnRequests, which is currently only supported via the HTTP-Redirect binding.'
            );
        }

        return [
            'ok'                    => true,
            'fatal'                 => false,
            'error'                 => null,
            'errorDescription'      => null,
            'sp'                    => $sp,
            'acsUrl'                => $acsUrl,
            'requestID'             => (string) $parsed['id'],
            'forceAuthn'            => (bool) ($parsed['forceAuthn'] ?? false),
            'isPassive'             => (bool) ($parsed['isPassive'] ?? false),
            'requestedNameIDFormat' => $parsed['requestedNameIDFormat'] ?? null,
        ];
    }

    // ========================================================================
    // ⏳ PENDING-REQUEST STORE (tblSAMLAuthnRequests — survives login/MFA)
    // ========================================================================

    /**
     * ⏳ Store a validated request as "pending" and return a random, opaque
     * resume handle (PLAINTEXT — only its SHA-256 hash is persisted, mirrors
     * `tblOAuthAuthCodes.codeHash`). The controller round-trips this handle
     * through `/login?redirect=/saml/sso?resume=<handle>`.
     *
     * @param array  $validated The `ok === true` result of {@see self::validateAuthnRequest()}.
     * @param string $relayState Opaque RelayState to echo back verbatim later, or ''.
     * @param string $ipAddress  Requesting IP (IPv4 or IPv6).
     * @return string The PLAINTEXT resume handle.
     */
    public static function issuePendingRequest(array $validated, string $relayState, string $ipAddress): string
    {
        $handle     = bin2hex(random_bytes(self::HANDLE_BYTES));
        $handleHash = hash('sha256', $handle);
        $ttlSeconds = (int) getSetting('saml.authnrequest_ttl', 600);
        $spID       = (int) $validated['sp']['spID'];

        Database::query(
            "INSERT INTO tblSAMLAuthnRequests
                (handleHash, spID, requestID, acsURL, relayState, forceAuthn, isPassive, requestedNameIDFormat, expiresAt, ipAddress)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)",
            [
                $handleHash,
                $spID,
                $validated['requestID'],
                $validated['acsUrl'],
                $relayState !== '' ? $relayState : null,
                $validated['forceAuthn'] ? 1 : 0,
                $validated['isPassive'] ? 1 : 0,
                $validated['requestedNameIDFormat'],
                $ttlSeconds,
                $ipAddress,
            ],
            'sisssiisis'
        );

        return $handle;
    }

    /**
     * ⏳ READ-ONLY lookup of a pending request by its plaintext resume
     * handle — does NOT consume it. Used to render the attribute-release
     * consent screen (which must be safely re-displayable on a page reload
     * without burning the single use) BEFORE the user's actual decision is
     * consumed via {@see self::consumePendingRequest()}.
     *
     * @param string $handle Plaintext resume handle.
     * @return array|null The pending row, or null if unknown/expired/already-consumed.
     */
    public static function peekPendingRequest(string $handle): ?array
    {
        if ($handle === '') {
            return null;
        }

        $handleHash = hash('sha256', $handle);

        return Database::fetchOne(
            "SELECT * FROM tblSAMLAuthnRequests WHERE handleHash = ? AND consumedAt IS NULL AND expiresAt > NOW()",
            [$handleHash],
            's'
        );
    }

    /**
     * ⏳ Atomically consume a pending request by its plaintext resume handle.
     *
     * Mirrors `tblOAuthAuthCodes.consumedAt`'s single-use discipline: the
     * `UPDATE ... WHERE consumedAt IS NULL AND expiresAt > NOW()` guard means
     * a second consume attempt on the SAME handle (replay) or a call after
     * expiry always affects zero rows. Called exactly ONCE per pending
     * request — at the moment a TERMINAL decision is reached (approve OR
     * deny), never merely to display the consent screen (see
     * {@see self::peekPendingRequest()} for that).
     *
     * @param string $handle Plaintext resume handle from the `?resume=` query parameter.
     * @return array|null The consumed row (with `spID`, `acsURL`, `relayState`, …), or null if unknown/expired/already-consumed (a replay is additionally alerted — see {@see self::handlePossibleReplay()}).
     */
    public static function consumePendingRequest(string $handle): ?array
    {
        if ($handle === '') {
            return null;
        }

        $handleHash = hash('sha256', $handle);

        Database::query(
            "UPDATE tblSAMLAuthnRequests SET consumedAt = NOW() WHERE handleHash = ? AND consumedAt IS NULL AND expiresAt > NOW()",
            [$handleHash],
            's'
        );

        if (Database::getAffectedRows() > 0) {
            return Database::fetchOne(
                "SELECT * FROM tblSAMLAuthnRequests WHERE handleHash = ?",
                [$handleHash],
                's'
            );
        }

        self::handlePossibleReplay($handleHash);

        return null;
    }

    /**
     * 🚨 Best-effort replay alerting — mirrors
     * {@see OAuthTokenService::handlePossibleReplay()}'s own pattern. Only
     * fires when the handle genuinely exists AND was already consumed
     * (distinguishing a real replay from a merely-unknown/expired handle,
     * which is unremarkable and not alerted on).
     *
     * @param string $handleHash SHA-256 hex of the presented handle.
     * @return void
     */
    private static function handlePossibleReplay(string $handleHash): void
    {
        $row = Database::fetchOne(
            "SELECT pendingID, spID, consumedAt FROM tblSAMLAuthnRequests WHERE handleHash = ? LIMIT 1",
            [$handleHash],
            's'
        );

        if ($row === null || empty($row['consumedAt'])) {
            return; // Unknown handle, or an unconsumed-but-expired row -- not a replay.
        }

        if (class_exists('SecurityAlertManager')) {
            try {
                SecurityAlertManager::create(
                    SecurityAlertManager::TYPE_SESSION_HIJACK,
                    SecurityAlertManager::SEVERITY_HIGH,
                    'SAML pending-AuthnRequest resume-handle replay detected.',
                    [
                        'event'     => 'saml_authnrequest_replay',
                        'pendingID' => (int) $row['pendingID'],
                        'spID'      => (int) $row['spID'],
                    ],
                    null,
                    function_exists('getClientIP') ? getClientIP() : null
                );
            } catch (\Throwable $e) {
                error_log('SamlAuthnRequestService::handlePossibleReplay alert failed: ' . $e->getMessage());
            }
        }

        if (class_exists('ActivityLogger')) {
            try {
                ActivityLogger::log(
                    null,
                    'saml_authnrequest_replay_detected',
                    'auth',
                    'critical',
                    'SAML pending-AuthnRequest resume-handle replay detected.',
                    ['pendingID' => (int) $row['pendingID'], 'spID' => (int) $row['spID']]
                );
            } catch (\Throwable $e) {
                error_log('SamlAuthnRequestService::handlePossibleReplay activity log failed: ' . $e->getMessage());
            }
        }
    }

    // ========================================================================
    // 🤝 ATTRIBUTE-RELEASE CONSENT (tblSAMLConsents — mirrors
    //    OAuthAuthorizeService's hasCoveringConsent()/consent-upsert pattern)
    // ========================================================================

    /**
     * 🤝 Does the user already have a live (non-revoked) consent for this SP
     * that COVERS every attribute name in `$attributeNames`?
     *
     * Used by the SSO controller to auto-skip the consent screen on a
     * repeat sign-in (unless the SP is `isFirstParty`, which the controller
     * checks separately and unconditionally skips consent for).
     *
     * @param int      $userID         Authenticated user.
     * @param int      $spID           Internal spID.
     * @param string[] $attributeNames The attribute names this SP's attributeMap would release.
     * @return bool True if a non-revoked consent row exists whose stored
     *              attribute list is a superset of `$attributeNames`.
     */
    public static function hasCoveringConsent(int $userID, int $spID, array $attributeNames): bool
    {
        if (empty($attributeNames)) {
            return true; // Nothing would be released — vacuously covered.
        }

        $row = Database::fetchOne(
            "SELECT attributes FROM tblSAMLConsents WHERE userID = ? AND spID = ? AND revokedAt IS NULL",
            [$userID, $spID],
            'ii'
        );
        if ($row === null) {
            return false;
        }

        $granted = preg_split('/\s+/', trim((string) $row['attributes']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($attributeNames as $name) {
            if (!in_array($name, $granted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 🤝 Record (or refresh) the user's consent for this SP's CURRENT
     * attribute-release set — REPLACES any prior stored list with exactly
     * what was just approved (mirrors
     * {@see OAuthAuthorizeService::issueAuthorizationCode()}'s consent
     * upsert semantics: reflect what was approved NOW, not a stale union
     * with an earlier, possibly narrower grant).
     *
     * @param int      $userID         Authenticated user.
     * @param int      $spID           Internal spID.
     * @param string[] $attributeNames The attribute names being released.
     * @param string   $ipAddress      Requesting IP (audit trail).
     * @return void
     */
    public static function recordConsent(int $userID, int $spID, array $attributeNames, string $ipAddress): void
    {
        $attributesString = implode(' ', array_unique($attributeNames));

        Database::query(
            "INSERT INTO tblSAMLConsents (userID, spID, attributes, grantedAt, revokedAt, ipAddress)
             VALUES (?, ?, ?, NOW(), NULL, ?)
             ON DUPLICATE KEY UPDATE attributes = VALUES(attributes), grantedAt = NOW(), revokedAt = NULL, ipAddress = VALUES(ipAddress)",
            [$userID, $spID, $attributesString, $ipAddress],
            'iiss'
        );
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🕰️ Parse a SAML `xs:dateTime` (UTC "Zulu") value into a UNIX timestamp.
     * Tolerates an optional fractional-seconds component
     * (`2026-08-04T12:00:00.123Z`), which SAML Core §1.3.3 permits.
     *
     * @param string $value Raw IssueInstant/NotBefore/NotOnOrAfter value.
     * @return int|null UNIX timestamp, or null if unparsable.
     */
    private static function parseSamlTime(string $value): ?int
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
        }

        return $dt !== false ? $dt->getTimestamp() : null;
    }

    /**
     * ❌ Build a FATAL result — the caller must render a local error page
     * and must NEVER respond anywhere (no proven-safe ACS target exists).
     *
     * @param string $error       Short machine-readable error code.
     * @param string $description Human-readable description.
     * @return array Shape matches {@see self::validateAuthnRequest()}'s return type.
     */
    private static function fatalError(string $error, string $description): array
    {
        return [
            'ok'                    => false,
            'fatal'                 => true,
            'error'                 => $error,
            'errorDescription'      => $description,
            'sp'                    => null,
            'acsUrl'                => null,
            'requestID'             => null,
            'forceAuthn'            => false,
            'isPassive'             => false,
            'requestedNameIDFormat' => null,
        ];
    }

    /**
     * ❌ Build a SAFE (non-fatal) error result — the caller MAY build a
     * signed SAML error `<Response>` and deliver it to `$acsUrl`, which has
     * ALREADY been proven to be on the SP's allowlist by this point.
     *
     * @param array       $sp          Hydrated SP row.
     * @param string      $acsUrl      Proven ACS URL.
     * @param array       $parsed      The parsed AuthnRequest (for requestID/forceAuthn/isPassive echo).
     * @param string      $error       Short machine-readable error code.
     * @param string      $description Human-readable description.
     * @return array Shape matches {@see self::validateAuthnRequest()}'s return type.
     */
    private static function safeError(array $sp, string $acsUrl, array $parsed, string $error, string $description): array
    {
        return [
            'ok'                    => false,
            'fatal'                 => false,
            'error'                 => $error,
            'errorDescription'      => $description,
            'sp'                    => $sp,
            'acsUrl'                => $acsUrl,
            'requestID'             => (string) ($parsed['id'] ?? ''),
            'forceAuthn'            => (bool) ($parsed['forceAuthn'] ?? false),
            'isPassive'             => (bool) ($parsed['isPassive'] ?? false),
            'requestedNameIDFormat' => $parsed['requestedNameIDFormat'] ?? null,
        ];
    }
}

// ✅ SamlAuthnRequestService loaded successfully
