<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - SAML 2.0 Identity-Provider: Response/Assertion Builder (#100)
 * ============================================================================
 *
 * Purpose:
 *   Assembles the `<samlp:Response>`/`<saml:Assertion>` DOM for SP-initiated
 *   SSO (G-001 Phase B, #100) — both the SUCCESS path and the SAML error
 *   path (`urn:oasis:names:tc:SAML:2.0:status:Requester`) — signs it per the
 *   SP's policy via {@see SamlXmlSignature} (B1), and records the
 *   issued-assertion ledger (`tblSAMLAssertions`) for audit/SLO/replay
 *   evidence.
 *
 * 🔒 Security notes baked in (plan §6.2, §9):
 *   - IDs: `'_' . bin2hex(random_bytes(20))` — a valid XML NCName (a bare
 *     hex string is NOT, since XML names cannot start with a digit —
 *     hence the leading underscore), 160 bits of entropy, UNIQUE forever
 *     via `tblSAMLAssertions.samlAssertionID`'s UNIQUE key.
 *   - Times: UTC "Zulu" (`Y-m-d\TH:i:s\Z`) throughout, `NotBefore` = now −
 *     `saml.clock_skew`, `NotOnOrAfter` = now + the SP's (or the global
 *     default) assertion TTL.
 *   - `<AudienceRestriction><Audience>` = the SP's own `entityID` — an
 *     assertion can only ever be replayed at the SP it was minted for.
 *   - `SubjectConfirmationData` carries `Recipient` (= the request's
 *     PROVEN ACS URL), `InResponseTo` (echoed from the consumed pending
 *     request — makes an unsolicited/forged response impossible to accept
 *     downstream while IdP-initiated SSO stays disabled), and its own
 *     `NotOnOrAfter`.
 *   - Persistent NameID reuses {@see OAuthClientManager::computeSubject()}'s
 *     EXACT pairwise formula (sector-scoped SHA-256 over a shared,
 *     server-side salt) — deliberately re-implemented here (not called
 *     directly; that method is private) against the SAME
 *     `oauth.pairwise_salt` setting, per the plan's open-question-#1
 *     recommended answer: a user's persistent SAML NameID and their OIDC
 *     pairwise `sub` for the "same sector" should be the SAME opaque value,
 *     not two independently-salted ones. ANY future change to the salt's
 *     storage shape must be applied to BOTH copies.
 *   - Serialization discipline: the WHOLE document is built in ONE
 *     `DOMDocument` (never string-spliced), no pretty-print whitespace
 *     inside signed elements (`formatOutput = false`), `saveXML()` called
 *     once at the very end.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-dom.
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf (SAML 2.0 Core — §2 Assertions, §3.2.2 Status Codes)
 *
 * @see web/private_html/security/SamlXmlSignature.php (B1 signing)
 * @see web/private_html/auth/OAuthUserInfoService.php (the claim-resolution codepath this mirrors for AttributeStatement)
 * @see web/private_html/auth/OAuthClientManager.php   (computeSubject() — the pairwise formula this class's persistent NameID mirrors)
 * @see _database/migrations/050_saml_idp.sql (tblSAMLAssertions)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class SamlResponseBuilder
{
    /** @var string SAML protocol namespace (samlp:). */
    private const SAMLP_NS = 'urn:oasis:names:tc:SAML:2.0:protocol';

    /** @var string SAML assertion namespace (saml:). */
    private const SAML_NS = 'urn:oasis:names:tc:SAML:2.0:assertion';

    /** @var string XML Schema Instance namespace (for typed AttributeValue). */
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';

    /** @var string SAML 2.0 "Success" top-level status code. */
    public const STATUS_SUCCESS = 'urn:oasis:names:tc:SAML:2.0:status:Success';

    /** @var string SAML 2.0 "Requester" top-level status code (the request itself was faulty). */
    public const STATUS_REQUESTER = 'urn:oasis:names:tc:SAML:2.0:status:Requester';

    /** @var string SAML 2.0 second-level status code for a passive request that could not be satisfied — nested under STATUS_RESPONDER per SAML Core §3.2.2.2. */
    public const STATUS_NO_PASSIVE = 'urn:oasis:names:tc:SAML:2.0:status:NoPassive';

    /** @var string SAML 2.0 "Responder" top-level status code (the IdP itself could not satisfy the request — the correct top-level wrapper for STATUS_NO_PASSIVE). */
    public const STATUS_RESPONDER = 'urn:oasis:names:tc:SAML:2.0:status:Responder';

    /** @var int Random bytes for assertion/response IDs — 20 bytes = 160-bit, per plan §4. */
    private const ID_BYTES = 20;

    /** @var string tblSettings key holding the (encrypted) pairwise-subject salt — SHARED with OAuthClientManager, see class header. */
    private const PAIRWISE_SALT_SETTING_KEY = 'oauth.pairwise_salt';

    /** @var int Random bytes for the pairwise-subject server salt (mint-on-first-use). */
    private const PAIRWISE_SALT_BYTES = 32;

    // ========================================================================
    // ✅ SUCCESS RESPONSE
    // ========================================================================

    /**
     * ✅ Build (and sign, per SP policy) a successful SSO `<Response>`.
     *
     * @param array  $sp           Hydrated SP row (from SamlServiceProviderManager).
     * @param int    $userID       The authenticated SIGNula userID.
     * @param string $inResponseTo The consumed pending request's `requestID`.
     * @param string $acsUrl       The PROVEN ACS URL (Recipient + Destination).
     * @param string $sessionIndex Ties this assertion to a SIGNula session (SLO correlation).
     * @param string $ipAddress    Requesting IP (ledger audit trail).
     * @return array{xml:string,assertionID:string,responseID:string}
     */
    public static function buildSuccessResponse(
        array $sp,
        int $userID,
        string $inResponseTo,
        string $acsUrl,
        string $sessionIndex,
        string $ipAddress
    ): array {
        $idpEntityId = (string) getSetting('saml.idp_entity_id', 'https://signula.id/saml/metadata');
        $now         = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ttlSeconds  = (int) ($sp['assertionTTLSeconds'] ?? getSetting('saml.assertion_ttl', 300));
        $skew        = (int) getSetting('saml.clock_skew', 120);
        $notBefore   = $now->modify('-' . $skew . ' seconds');
        $notOnOrAfter = $now->modify('+' . $ttlSeconds . ' seconds');

        $assertionId = self::generateId();
        $responseId  = self::generateId();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false; // 🚫 No pretty-print whitespace inside a document that will be XML-DSig signed.

        // ---- <samlp:Response> ----
        $response = $dom->createElementNS(self::SAMLP_NS, 'samlp:Response');
        $response->setAttribute('ID', $responseId);
        $response->setAttribute('Version', '2.0');
        $response->setAttribute('IssueInstant', self::formatSamlTime($now));
        $response->setAttribute('Destination', $acsUrl);
        $response->setAttribute('InResponseTo', $inResponseTo);
        $dom->appendChild($response);

        $response->appendChild($dom->createElementNS(self::SAML_NS, 'saml:Issuer', $idpEntityId));

        $status = self::buildStatus($dom, self::STATUS_SUCCESS, null);
        $response->appendChild($status);

        // ---- <saml:Assertion> ----
        $assertion = $dom->createElementNS(self::SAML_NS, 'saml:Assertion');
        $assertion->setAttribute('ID', $assertionId);
        $assertion->setAttribute('Version', '2.0');
        $assertion->setAttribute('IssueInstant', self::formatSamlTime($now));
        $response->appendChild($assertion);

        $assertion->appendChild($dom->createElementNS(self::SAML_NS, 'saml:Issuer', $idpEntityId));

        // ---- <saml:Subject> ----
        $nameIdValue    = self::resolveNameId($sp, $userID);
        $nameIdFormatUri = SamlMetadataService::NAMEID_FORMAT_URIS[(string) ($sp['nameIDFormat'] ?? 'emailAddress')]
            ?? SamlMetadataService::NAMEID_FORMAT_URIS['unspecified'];

        $subject = $dom->createElementNS(self::SAML_NS, 'saml:Subject');
        $assertion->appendChild($subject);

        $nameId = $dom->createElementNS(self::SAML_NS, 'saml:NameID', $nameIdValue);
        $nameId->setAttribute('Format', $nameIdFormatUri);
        $subject->appendChild($nameId);

        $subjectConfirmation = $dom->createElementNS(self::SAML_NS, 'saml:SubjectConfirmation');
        $subjectConfirmation->setAttribute('Method', 'urn:oasis:names:tc:SAML:2.0:cm:bearer');
        $subject->appendChild($subjectConfirmation);

        $subjectConfirmationData = $dom->createElementNS(self::SAML_NS, 'saml:SubjectConfirmationData');
        $subjectConfirmationData->setAttribute('Recipient', $acsUrl);
        $subjectConfirmationData->setAttribute('InResponseTo', $inResponseTo);
        $subjectConfirmationData->setAttribute('NotOnOrAfter', self::formatSamlTime($notOnOrAfter));
        $subjectConfirmation->appendChild($subjectConfirmationData);

        // ---- <saml:Conditions> ----
        $conditions = $dom->createElementNS(self::SAML_NS, 'saml:Conditions');
        $conditions->setAttribute('NotBefore', self::formatSamlTime($notBefore));
        $conditions->setAttribute('NotOnOrAfter', self::formatSamlTime($notOnOrAfter));
        $assertion->appendChild($conditions);

        $audienceRestriction = $dom->createElementNS(self::SAML_NS, 'saml:AudienceRestriction');
        $conditions->appendChild($audienceRestriction);
        $audienceRestriction->appendChild($dom->createElementNS(self::SAML_NS, 'saml:Audience', (string) $sp['entityID']));

        // ---- <saml:AuthnStatement> ----
        $authnStatement = $dom->createElementNS(self::SAML_NS, 'saml:AuthnStatement');
        $authnStatement->setAttribute('AuthnInstant', self::formatSamlTime($now));
        $authnStatement->setAttribute('SessionIndex', $sessionIndex);
        $assertion->appendChild($authnStatement);

        $authnContext = $dom->createElementNS(self::SAML_NS, 'saml:AuthnContext');
        $authnStatement->appendChild($authnContext);
        // 🔖 v1 always asserts PasswordProtectedTransport regardless of whether
        //    MFA was actually used (plan open question #3 — no clean standard
        //    MFA context-class URN; flagged for a future decision, NOT a v1 gap).
        $authnContext->appendChild($dom->createElementNS(
            self::SAML_NS,
            'saml:AuthnContextClassRef',
            'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport'
        ));

        // ---- <saml:AttributeStatement> (only if the SP has an attributeMap) ----
        $attributeMap = is_array($sp['attributeMap'] ?? null) ? $sp['attributeMap'] : [];
        if (!empty($attributeMap)) {
            $attributeStatement = self::buildAttributeStatement($dom, $userID, $attributeMap);
            if ($attributeStatement !== null) {
                $assertion->appendChild($attributeStatement);
            }
        }

        // ---- Sign per SP policy (Assertion FIRST, then Response — plan §6.2) ----
        $activeKey = SamlKeyManager::getActiveKey();
        if (!empty($sp['signAssertion'])) {
            SamlXmlSignature::signAssertion($assertion, $subject, $activeKey['private_pem'], $activeKey['certificate_pem']);
        }
        if (!empty($sp['signResponse'])) {
            SamlXmlSignature::signResponse($response, $status, $activeKey['private_pem'], $activeKey['certificate_pem']);
        }

        // ---- Ledger (audit + SLO correlation + replay evidence) ----
        Database::query(
            "INSERT INTO tblSAMLAssertions
                (samlAssertionID, spID, userID, inResponseTo, sessionIndex, nameIDValue, notOnOrAfter, ipAddress)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $assertionId,
                (int) $sp['spID'],
                $userID,
                $inResponseTo,
                $sessionIndex,
                $nameIdValue,
                $notOnOrAfter->format('Y-m-d H:i:s'),
                $ipAddress,
            ],
            'siisssss'
        );

        return [
            'xml'         => $dom->saveXML(),
            'assertionID' => $assertionId,
            'responseID'  => $responseId,
        ];
    }

    // ========================================================================
    // ❌ ERROR RESPONSE (safe-to-respond path — see SamlAuthnRequestService)
    // ========================================================================

    /**
     * ❌ Build a SAML error `<Response>` (no `<Assertion>`) — used for every
     * "safe" (non-fatal) validation failure, where the ACS URL has already
     * been proven to belong to the requesting SP.
     *
     * @param array       $sp             Hydrated SP row.
     * @param string      $acsUrl         The PROVEN ACS URL.
     * @param string|null $inResponseTo   The request's ID, if one was parsed (may be null for a very early failure).
     * @param string      $statusCodeValue One of the `self::STATUS_*` constants (top-level).
     * @param string      $statusMessage   Human-readable detail (never leaks internals — caller supplies a safe, generic message).
     * @param string|null $secondLevelStatusCodeValue Optional nested `self::STATUS_*` value (SAML Core §3.2.2.2 second-level status code — e.g. `STATUS_NO_PASSIVE` nested under `STATUS_RESPONDER`).
     * @return array{xml:string,responseID:string}
     */
    public static function buildErrorResponse(
        array $sp,
        string $acsUrl,
        ?string $inResponseTo,
        string $statusCodeValue,
        string $statusMessage,
        ?string $secondLevelStatusCodeValue = null
    ): array {
        $idpEntityId = (string) getSetting('saml.idp_entity_id', 'https://signula.id/saml/metadata');
        $now         = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $responseId  = self::generateId();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $response = $dom->createElementNS(self::SAMLP_NS, 'samlp:Response');
        $response->setAttribute('ID', $responseId);
        $response->setAttribute('Version', '2.0');
        $response->setAttribute('IssueInstant', self::formatSamlTime($now));
        $response->setAttribute('Destination', $acsUrl);
        if ($inResponseTo !== null && $inResponseTo !== '') {
            $response->setAttribute('InResponseTo', $inResponseTo);
        }
        $dom->appendChild($response);

        $response->appendChild($dom->createElementNS(self::SAML_NS, 'saml:Issuer', $idpEntityId));

        $status = self::buildStatus($dom, $statusCodeValue, $statusMessage, $secondLevelStatusCodeValue);
        $response->appendChild($status);

        // 🔏 Best-effort: sign the error Response too if the SP wants a signed
        //    Response for successes (not spec-mandated for errors, but
        //    consistent + harmless, and some SPs are stricter about this).
        if (!empty($sp['signResponse'])) {
            $activeKey = SamlKeyManager::getActiveKey();
            SamlXmlSignature::signResponse($response, $status, $activeKey['private_pem'], $activeKey['certificate_pem']);
        }

        return ['xml' => $dom->saveXML(), 'responseID' => $responseId];
    }

    // ========================================================================
    // 🔧 SHARED DOM HELPERS
    // ========================================================================

    /**
     * 🧱 Build a `<samlp:Status>` element, with an optional nested
     * second-level `<samlp:StatusCode>` (SAML Core §3.2.2.2 — e.g.
     * `STATUS_NO_PASSIVE` nested under `STATUS_RESPONDER`).
     *
     * @param DOMDocument $dom Owning document.
     * @param string      $statusCodeValue A `self::STATUS_*` URN (top-level).
     * @param string|null $statusMessage   Optional human-readable message.
     * @param string|null $secondLevelStatusCodeValue Optional nested `self::STATUS_*` URN.
     * @return DOMElement
     */
    private static function buildStatus(
        DOMDocument $dom,
        string $statusCodeValue,
        ?string $statusMessage,
        ?string $secondLevelStatusCodeValue = null
    ): DOMElement {
        $status = $dom->createElementNS(self::SAMLP_NS, 'samlp:Status');

        $statusCode = $dom->createElementNS(self::SAMLP_NS, 'samlp:StatusCode');
        $statusCode->setAttribute('Value', $statusCodeValue);
        $status->appendChild($statusCode);

        if ($secondLevelStatusCodeValue !== null && $secondLevelStatusCodeValue !== '') {
            $nestedStatusCode = $dom->createElementNS(self::SAMLP_NS, 'samlp:StatusCode');
            $nestedStatusCode->setAttribute('Value', $secondLevelStatusCodeValue);
            $statusCode->appendChild($nestedStatusCode);
        }

        if ($statusMessage !== null && $statusMessage !== '') {
            $status->appendChild($dom->createElementNS(self::SAMLP_NS, 'samlp:StatusMessage', $statusMessage));
        }

        return $status;
    }

    /**
     * 🧱 Build a `<saml:AttributeStatement>` from the SP's attributeMap,
     * sourcing values via the SAME `tblUsers` claim vocabulary
     * {@see OAuthUserInfoService::addGatedClaims()} already emits for OIDC.
     *
     * @param DOMDocument          $dom          Owning document.
     * @param int                  $userID       Subject userID.
     * @param array<string,string> $attributeMap SAML attribute name => claim name.
     * @return DOMElement|null null if no claim in the map actually resolved to a value (e.g. an empty tblUsers field).
     */
    private static function buildAttributeStatement(DOMDocument $dom, int $userID, array $attributeMap): ?DOMElement
    {
        $claims = self::resolveClaims($userID, array_values($attributeMap));
        if (empty($claims)) {
            return null;
        }

        $attributeStatement = $dom->createElementNS(self::SAML_NS, 'saml:AttributeStatement');
        $wroteAny = false;

        foreach ($attributeMap as $samlAttributeName => $claimName) {
            if (!array_key_exists($claimName, $claims)) {
                continue; // Claim not available for this user — simply omit (never emit an empty AttributeValue).
            }

            $attribute = $dom->createElementNS(self::SAML_NS, 'saml:Attribute');
            $attribute->setAttribute('Name', (string) $samlAttributeName);
            $attribute->setAttribute('NameFormat', 'urn:oasis:names:tc:SAML:2.0:attrname-format:unspecified');

            $attributeValue = $dom->createElementNS(self::SAML_NS, 'saml:AttributeValue', (string) $claims[$claimName]);
            $attributeValue->setAttributeNS(self::XSI_NS, 'xsi:type', 'xs:string');
            $attribute->appendChild($attributeValue);

            $attributeStatement->appendChild($attribute);
            $wroteAny = true;
        }

        return $wroteAny ? $attributeStatement : null;
    }

    /**
     * 🏷️ Resolve the tblUsers-sourced claim values needed for an
     * AttributeStatement — mirrors
     * {@see OAuthUserInfoService::addGatedClaims()}'s claim vocabulary
     * exactly (no SAML-specific claim names are invented here).
     *
     * @param int      $userID     Subject userID.
     * @param string[] $claimNames The distinct claim names the SP's attributeMap references.
     * @return array<string,string> claim name => string value (only claims that actually have a non-empty value).
     */
    private static function resolveClaims(int $userID, array $claimNames): array
    {
        $user = Database::fetchOne(
            "SELECT email, emailVerified, displayName, firstName, lastName, username, profilePicture
             FROM tblUsers WHERE userID = ? LIMIT 1",
            [$userID],
            'i'
        );
        if ($user === null) {
            return [];
        }

        $claims = [];

        if (in_array('email', $claimNames, true) && !empty($user['email'])) {
            $claims['email'] = (string) $user['email'];
        }
        if (in_array('email_verified', $claimNames, true)) {
            $claims['email_verified'] = !empty($user['emailVerified']) ? 'true' : 'false';
        }
        if (in_array('name', $claimNames, true) && !empty($user['displayName'])) {
            $claims['name'] = (string) $user['displayName'];
        }
        if (in_array('given_name', $claimNames, true) && !empty($user['firstName'])) {
            $claims['given_name'] = (string) $user['firstName'];
        }
        if (in_array('family_name', $claimNames, true) && !empty($user['lastName'])) {
            $claims['family_name'] = (string) $user['lastName'];
        }
        if (in_array('preferred_username', $claimNames, true) && !empty($user['username'])) {
            $claims['preferred_username'] = (string) $user['username'];
        }
        if (in_array('picture', $claimNames, true) && !empty($user['profilePicture'])) {
            $claims['picture'] = (string) $user['profilePicture'];
        }

        return $claims;
    }

    /**
     * 🕵️ Resolve the `<saml:NameID>` value per the SP's configured format.
     *
     * @param array $sp     Hydrated SP row.
     * @param int   $userID Authenticated userID.
     * @return string
     */
    private static function resolveNameId(array $sp, int $userID): string
    {
        $format = (string) ($sp['nameIDFormat'] ?? 'emailAddress');

        switch ($format) {
            case 'persistent':
                return self::computePersistentNameId($userID, (string) $sp['entityID']);

            case 'transient':
                // 🔄 Fresh, unlinkable per-response value — never reused,
                //    never persisted anywhere but this single assertion's
                //    nameIDValue ledger column (audit only, not a lookup key).
                return self::generateId();

            case 'unspecified':
                return (string) $userID;

            case 'emailAddress':
            default:
                $user = Database::fetchOne("SELECT email FROM tblUsers WHERE userID = ? LIMIT 1", [$userID], 'i');
                return $user !== null && !empty($user['email']) ? (string) $user['email'] : (string) $userID;
        }
    }

    /**
     * 🕵️ Compute a persistent, pairwise NameID — SAME formula as
     * {@see OAuthClientManager::computeSubject()} (`SHA-256(sector|userID|salt)`),
     * against the SAME shared `oauth.pairwise_salt` setting — see class
     * header for why this is deliberately duplicated rather than calling
     * that (private) method directly.
     *
     * @param int    $userID Subject userID.
     * @param string $sector The SP's entityID (the pairwise "sector").
     * @return string 64-char lowercase hex (SHA-256).
     */
    private static function computePersistentNameId(int $userID, string $sector): string
    {
        return hash('sha256', $sector . '|' . $userID . '|' . self::getPairwiseSalt());
    }

    /**
     * 🔑 Read (or, on first use, mint + persist) the SHARED pairwise-subject
     * salt — mirrors {@see OAuthClientManager::getPairwiseSalt()}'s exact
     * "INSERT IGNORE + re-read" concurrency-safe pattern.
     *
     * @return string Decrypted salt.
     * @throws \RuntimeException If the salt can neither be read nor created.
     */
    private static function getPairwiseSalt(): string
    {
        $row = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [self::PAIRWISE_SALT_SETTING_KEY],
            's'
        );
        if ($row !== null && !empty($row['settingValue'])) {
            return SecurityUtils::decrypt((string) $row['settingValue']);
        }

        $salt = bin2hex(random_bytes(self::PAIRWISE_SALT_BYTES));
        Database::query(
            "INSERT IGNORE INTO tblSettings
                (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
             VALUES (?, ?, 'encrypted', 1, 'oauth', 'Server-side pairwise-subject salt (shared by OIDC pairwise sub + SAML persistent NameID) -- auto-generated on first use')",
            [self::PAIRWISE_SALT_SETTING_KEY, SecurityUtils::encrypt($salt)],
            'ss'
        );

        $row = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [self::PAIRWISE_SALT_SETTING_KEY],
            's'
        );
        if ($row === null || empty($row['settingValue'])) {
            throw new \RuntimeException('SamlResponseBuilder: failed to establish oauth.pairwise_salt');
        }

        return SecurityUtils::decrypt((string) $row['settingValue']);
    }

    /**
     * 🎲 Generate a SAML-shaped ID: `'_' . bin2hex(random_bytes(20))`.
     *
     * The leading underscore is REQUIRED — XML NCName syntax forbids a
     * name starting with a digit, and a bare hex string may start with one.
     *
     * @return string
     */
    private static function generateId(): string
    {
        return '_' . bin2hex(random_bytes(self::ID_BYTES));
    }

    /**
     * 🕰️ Format a UTC instant as a SAML `xs:dateTime` ("Zulu") string.
     *
     * @param \DateTimeImmutable $dt
     * @return string
     */
    private static function formatSamlTime(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
}

// ✅ SamlResponseBuilder loaded successfully
