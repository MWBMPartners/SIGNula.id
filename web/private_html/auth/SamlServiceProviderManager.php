<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🪪 SIGNula - SAML 2.0 Identity-Provider: Service Provider Registration Manager
 * ============================================================================
 *
 * Purpose:
 *   Owns the lifecycle of registered SAML 2.0 Service Providers (SPs) — the
 *   enterprise apps that will consume "Sign in with SIGNula.id" via SAML
 *   rather than OIDC (G-001 Phase B, #100). Mirrors
 *   {@see OAuthClientManager}'s shape deliberately (registration, the
 *   EXACT-MATCH ACS-URL allowlist, activation policy, certificate handling)
 *   — see that class's own header for the security rationale this class
 *   transplants wholesale from the OIDC provider.
 *
 * Security properties (mirrors OAuthClientManager §"Security properties"):
 *   • isExactAcsMatch() does a BYTE-EXACT PHP string comparison (strict ===)
 *     — no prefix/substring/wildcard/normalisation of any kind. This is the
 *     #1 SAML IdP security control (the redirect_uri rule transplanted).
 *     The backing table also uses a byte-sensitive collation
 *     (utf8mb4_bin) as DB-level defence-in-depth, but correctness NEVER
 *     depends on that — this PHP comparison is authoritative.
 *   • activate() is the ONLY path that ever sets isActive=1, and it REFUSES
 *     to activate an SP that: (a) has `wantAssertionsEncrypted=1` (XML-Enc
 *     is v2, out of scope — plan §4/§8 B3), (b) has neither `signAssertion`
 *     nor `signResponse` enabled (at least one MUST be signed, plan §6.2),
 *     or (c) has zero registered ACS URLs (nothing to ever respond to).
 *   • registerServiceProvider() ALWAYS forces `isActive=0` at INSERT time
 *     regardless of what a caller passes — activation is a deliberate,
 *     separate, audited step, never a side effect of registration (mirrors
 *     migration 050's dormant-by-default row design).
 *   • Certificates are structurally validated (openssl_x509_read) before
 *     being stored — a syntactically-broken cert can never silently become
 *     "the pinned verifier" for a request-signature check.
 *
 * Testability:
 *   registerServiceProvider()/activate()/deactivate()/getByEntityID()/
 *   listByPartner() are DB-bound (Integration tests). isExactAcsMatch(),
 *   validateAttributeMap(), and validateCertificatePem() are pure functions
 *   with NO database access — Unit-testable directly.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-metadata-2.0-os.pdf (SAML 2.0 Metadata)
 *
 * @see web/private_html/auth/OAuthClientManager.php (the manager-class shape this mirrors)
 * @see _database/migrations/050_saml_idp.sql (tblSAMLServiceProviders / tblSAMLServiceProviderAcsUrls)
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
 * 🪪 SAML 2.0 Service Provider registration manager.
 *
 * All methods are static (matches the OAuthClientManager / SecurityUtils /
 * KeyManager house style).
 */
class SamlServiceProviderManager
{
    /** @var string[] Valid tblSAMLServiceProviders.nameIDFormat ENUM values. */
    private const VALID_NAMEID_FORMATS = ['emailAddress', 'persistent', 'transient', 'unspecified'];

    /** @var string[] Valid tblSAMLServiceProviders.sloBinding ENUM values. */
    private const VALID_SLO_BINDINGS = ['HTTP-Redirect', 'HTTP-POST'];

    /** @var string[] Valid tblSAMLServiceProviderAcsUrls.binding ENUM values (v1: POST only, per migration 050). */
    private const VALID_ACS_BINDINGS = ['HTTP-POST'];

    /** @var int Max length of tblSAMLServiceProviders.displayName (VARCHAR(150)). */
    private const MAX_DISPLAY_NAME_LENGTH = 150;

    /** @var int Max length of an entityID / ACS URL / SLO URL (VARCHAR(500) columns). */
    private const MAX_URL_LENGTH = 500;

    /**
     * 🗺️ The claim vocabulary an attributeMap value may reference — the SAME
     * claim names IdTokenBuilder/OAuthUserInfoService already emit for OIDC,
     * so SamlResponseBuilder can reuse one already-tested claim-resolution
     * codepath for both protocols.
     *
     * @var string[]
     */
    private const VALID_CLAIM_NAMES = [
        'email', 'email_verified', 'name', 'given_name', 'family_name',
        'preferred_username', 'picture',
    ];

    // ========================================================================
    // 📝 REGISTRATION
    // ========================================================================

    /**
     * 📝 Register a new SAML Service Provider.
     *
     * Inserts the SP row + its ACS-URL allowlist inside a single transaction
     * so the two can never be left inconsistent. Always inserts with
     * `isActive = 0` — see class header; call {@see self::activate()}
     * separately once the registration is verified sane.
     *
     * @param int|null $partnerID  Owning partner, or null for a first-party/
     *                             global SIGNula-owned SP.
     * @param string   $entityID   The SP's entityID (byte-exact match key).
     * @param string   $displayName Human-readable name (shown on the
     *                              attribute-release consent screen).
     * @param string[] $acsUrls    One or more exact ACS URL values (at least
     *                             one required). The FIRST one is marked
     *                             `isDefault=1` unless `opts['defaultAcsUrl']`
     *                             names a different one from the list.
     * @param array<string,mixed> $opts Optional overrides:
     *   - nameIDFormat            (string) default 'emailAddress'
     *   - attributeMap            (array<string,string>|null) SAML attribute name => claim name
     *   - spSigningCertPEM        (string|null) SP's X.509 cert PEM
     *   - wantAuthnRequestsSigned (bool) default false
     *   - signAssertion           (bool) default true
     *   - signResponse            (bool) default false
     *   - wantAssertionsEncrypted (bool) default false (v1 refuses to ACTIVATE if true)
     *   - sloURL                  (string|null)
     *   - sloBinding              (string|null) 'HTTP-Redirect'|'HTTP-POST'
     *   - assertionTTLSeconds     (int) default 300
     *   - requireConsent          (bool) default true
     *   - isFirstParty            (bool) default false
     *   - defaultAcsUrl           (string|null) which of $acsUrls is the default
     *   - createdBy               (int|null) userID performing the registration
     * @return array{spID:int,entityID:string,serviceProvider:array}|false
     *         `false` on any validation failure.
     */
    public static function registerServiceProvider(
        ?int $partnerID,
        string $entityID,
        string $displayName,
        array $acsUrls,
        array $opts = []
    ): array|false {
        $entityID = trim($entityID);
        if ($entityID === '' || strlen($entityID) > self::MAX_URL_LENGTH || !self::isValidUrlFormat($entityID)) {
            error_log('SamlServiceProviderManager::registerServiceProvider — invalid entityID');
            return false;
        }

        $displayName = trim(SecurityUtils::sanitizeString($displayName));
        if ($displayName === '' || strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
            error_log('SamlServiceProviderManager::registerServiceProvider — invalid displayName');
            return false;
        }

        $acsUrls = self::normaliseAcsUrls($acsUrls);
        if (empty($acsUrls)) {
            error_log('SamlServiceProviderManager::registerServiceProvider — no valid ACS URL supplied');
            return false;
        }

        $nameIDFormat = (string) ($opts['nameIDFormat'] ?? 'emailAddress');
        if (!in_array($nameIDFormat, self::VALID_NAMEID_FORMATS, true)) {
            error_log("SamlServiceProviderManager::registerServiceProvider — invalid nameIDFormat '{$nameIDFormat}'");
            return false;
        }

        $attributeMapJson = null;
        if (isset($opts['attributeMap']) && is_array($opts['attributeMap'])) {
            if (!self::validateAttributeMap($opts['attributeMap'])) {
                error_log('SamlServiceProviderManager::registerServiceProvider — invalid attributeMap');
                return false;
            }
            $attributeMapJson = json_encode($opts['attributeMap'], JSON_UNESCAPED_SLASHES);
        }

        $spSigningCertPEM = null;
        if (isset($opts['spSigningCertPEM']) && is_string($opts['spSigningCertPEM']) && trim($opts['spSigningCertPEM']) !== '') {
            $spSigningCertPEM = trim($opts['spSigningCertPEM']);
            if (!self::validateCertificatePem($spSigningCertPEM)) {
                error_log('SamlServiceProviderManager::registerServiceProvider — spSigningCertPEM does not parse as a valid X.509 certificate');
                return false;
            }
        }

        $wantAuthnRequestsSigned = (bool) ($opts['wantAuthnRequestsSigned'] ?? false);
        $signAssertion           = (bool) ($opts['signAssertion'] ?? true);
        $signResponse            = (bool) ($opts['signResponse'] ?? false);
        $wantAssertionsEncrypted = (bool) ($opts['wantAssertionsEncrypted'] ?? false);

        $sloURL = null;
        if (isset($opts['sloURL']) && is_string($opts['sloURL']) && trim($opts['sloURL']) !== '') {
            $sloURL = trim($opts['sloURL']);
            if (strlen($sloURL) > self::MAX_URL_LENGTH || !self::isValidUrlFormat($sloURL)) {
                error_log('SamlServiceProviderManager::registerServiceProvider — invalid sloURL');
                return false;
            }
        }

        $sloBinding = (string) ($opts['sloBinding'] ?? 'HTTP-Redirect');
        if (!in_array($sloBinding, self::VALID_SLO_BINDINGS, true)) {
            $sloBinding = 'HTTP-Redirect';
        }

        $assertionTTLSeconds = (int) ($opts['assertionTTLSeconds'] ?? 300);
        if ($assertionTTLSeconds < 30 || $assertionTTLSeconds > 3600) {
            // 🛡️ Sanity floor/ceiling — never accept a TTL so short it can
            //    race clock skew, or so long it becomes a meaningful replay window.
            $assertionTTLSeconds = 300;
        }

        $requireConsent = (bool) ($opts['requireConsent'] ?? true);
        $isFirstParty   = (bool) ($opts['isFirstParty'] ?? false);
        $createdBy      = isset($opts['createdBy']) ? (int) $opts['createdBy'] : null;

        $defaultAcsUrl = isset($opts['defaultAcsUrl']) && is_string($opts['defaultAcsUrl'])
            ? trim($opts['defaultAcsUrl'])
            : null;

        Database::beginTransaction();
        try {
            Database::query(
                "INSERT INTO tblSAMLServiceProviders (
                    partnerID, entityID, displayName, nameIDFormat, attributeMap,
                    spSigningCertPEM, wantAuthnRequestsSigned, signAssertion, signResponse,
                    wantAssertionsEncrypted, sloURL, sloBinding, assertionTTLSeconds,
                    requireConsent, isFirstParty, isActive, createdBy
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)",
                [
                    $partnerID,
                    $entityID,
                    $displayName,
                    $nameIDFormat,
                    $attributeMapJson,
                    $spSigningCertPEM,
                    $wantAuthnRequestsSigned ? 1 : 0,
                    $signAssertion ? 1 : 0,
                    $signResponse ? 1 : 0,
                    $wantAssertionsEncrypted ? 1 : 0,
                    $sloURL,
                    $sloBinding,
                    $assertionTTLSeconds,
                    $requireConsent ? 1 : 0,
                    $isFirstParty ? 1 : 0,
                    $createdBy,
                ],
                'isssssiiiissiiii'
            );

            $spID = Database::getLastInsertId();

            foreach ($acsUrls as $index => $acsUrl) {
                $isDefault = $defaultAcsUrl !== null
                    ? ($acsUrl === $defaultAcsUrl)
                    : ($index === 0); // 🎯 First URL is the default unless overridden.

                Database::query(
                    "INSERT INTO tblSAMLServiceProviderAcsUrls (spID, acsURL, binding, isDefault) VALUES (?, ?, 'HTTP-POST', ?)",
                    [$spID, $acsUrl, $isDefault ? 1 : 0],
                    'isi'
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            error_log('SamlServiceProviderManager::registerServiceProvider — transaction failed: ' . $e->getMessage());
            return false;
        }

        self::logEvent(
            'saml_sp_registered',
            $createdBy,
            "SAML Service Provider \"{$displayName}\" registered (entityID={$entityID})",
            ['spID' => $spID, 'entityID' => $entityID, 'partnerID' => $partnerID]
        );

        return [
            'spID'            => $spID,
            'entityID'        => $entityID,
            'serviceProvider' => self::getBySpID($spID),
        ];
    }

    // ========================================================================
    // 🚦 ACTIVATION / DEACTIVATION
    // ========================================================================

    /**
     * ✅ Activate a Service Provider — the ONLY path that ever sets
     * `isActive = 1`. Refuses (returns false, no DB write) when the SP's
     * configuration is not yet safe/complete to serve:
     *   - `wantAssertionsEncrypted = 1` (XML-Enc is v2 — plan §4/§8 B3).
     *   - Neither `signAssertion` nor `signResponse` is enabled (at least
     *     one MUST be signed — plan §6.2).
     *   - Zero registered ACS URLs.
     *
     * @param int $spID Internal spID.
     * @return bool True if activated; false if not found or refused.
     */
    public static function activate(int $spID): bool
    {
        $sp = self::getBySpID($spID);
        if ($sp === null) {
            return false;
        }

        if (!empty($sp['wantAssertionsEncrypted'])) {
            error_log("SamlServiceProviderManager::activate — refusing spID {$spID}: wantAssertionsEncrypted=1 (XML-Enc is v2, out of scope)");
            return false;
        }

        if (empty($sp['signAssertion']) && empty($sp['signResponse'])) {
            error_log("SamlServiceProviderManager::activate — refusing spID {$spID}: neither signAssertion nor signResponse is enabled");
            return false;
        }

        if (empty($sp['acsUrls'])) {
            error_log("SamlServiceProviderManager::activate — refusing spID {$spID}: no registered ACS URLs");
            return false;
        }

        Database::query(
            "UPDATE tblSAMLServiceProviders SET isActive = 1, updatedAt = NOW() WHERE spID = ?",
            [$spID],
            'i'
        );
        $success = Database::getAffectedRows() > 0;

        if ($success) {
            self::logEvent(
                'saml_sp_activated',
                null,
                "SAML Service Provider activated (spID {$spID})",
                ['spID' => $spID]
            );
        }

        return $success;
    }

    /**
     * 🚫 Deactivate a Service Provider (soft-disable — existing ledger rows
     * in tblSAMLAssertions/tblSAMLConsents are left alone for audit; the SSO
     * flow controller enforces `isActive` at request time).
     *
     * @param int $spID Internal spID.
     * @return bool Success.
     */
    public static function deactivate(int $spID): bool
    {
        Database::query(
            "UPDATE tblSAMLServiceProviders SET isActive = 0, updatedAt = NOW() WHERE spID = ?",
            [$spID],
            'i'
        );
        $success = Database::getAffectedRows() > 0;

        if ($success) {
            self::logEvent(
                'saml_sp_deactivated',
                null,
                "SAML Service Provider deactivated (spID {$spID})",
                ['spID' => $spID]
            );
        }

        return $success;
    }

    // ========================================================================
    // 🔍 RETRIEVAL
    // ========================================================================

    /**
     * 🔍 Fetch a Service Provider by its entityID (byte-exact DB lookup —
     * the AUTHORITATIVE match against an inbound `<Issuer>` still happens by
     * comparing THIS row's `entityID` column value with `===` in the caller,
     * exactly as {@see OAuthClientManager::getClient()} does for `client_id`).
     *
     * @param string $entityID SP entityID.
     * @return array|null Hydrated SP row (with `acsUrls`), or null if not found.
     */
    public static function getByEntityID(string $entityID): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM tblSAMLServiceProviders WHERE entityID = ?",
            [$entityID],
            's'
        );

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * 🔍 Fetch a Service Provider by its internal spID (PK).
     *
     * @param int $spID Internal spID.
     * @return array|null Hydrated SP row, or null if not found.
     */
    public static function getBySpID(int $spID): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM tblSAMLServiceProviders WHERE spID = ?",
            [$spID],
            'i'
        );

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * 📋 List all Service Providers owned by a partner (ownership-scoped).
     *
     * @param int $partnerID Owning partner.
     * @return array<int,array> Hydrated SP rows, newest first.
     */
    public static function listByPartner(int $partnerID): array
    {
        $rows = Database::fetchAll(
            "SELECT * FROM tblSAMLServiceProviders WHERE partnerID = ? ORDER BY createdAt DESC",
            [$partnerID],
            'i'
        );

        return array_map(static fn(array $row): array => self::hydrate($row), $rows);
    }

    /**
     * 📋 List every registered Service Provider (global admin oversight).
     *
     * @param bool $activeOnly When true, only rows with isActive=1.
     * @return array<int,array> Hydrated SP rows, newest first.
     */
    public static function listAll(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM tblSAMLServiceProviders";
        if ($activeOnly) {
            $sql .= " WHERE isActive = 1";
        }
        $sql .= " ORDER BY createdAt DESC";

        $rows = Database::fetchAll($sql);

        return array_map(static fn(array $row): array => self::hydrate($row), $rows);
    }

    // ========================================================================
    // 🛡️ VALIDATION — the security-critical checks
    // ========================================================================

    /**
     * 🎯 EXACT-MATCH ACS-URL validation (the #1 SAML IdP security control).
     * Fetches the SP's registered allowlist and delegates to
     * {@see self::isExactAcsMatch()} for a byte-exact PHP comparison.
     *
     * @param int    $spID Internal spID.
     * @param string $acsURL Candidate AssertionConsumerServiceURL.
     * @return bool True only if $acsURL byte-exactly matches a registered URL.
     */
    public static function validateAcsUrl(int $spID, string $acsURL): bool
    {
        $registered = self::listAcsUrlStrings($spID);

        return self::isExactAcsMatch($acsURL, $registered);
    }

    /**
     * 🎯 Pure, DB-free EXACT-MATCH comparison: is `$candidate` byte-identical
     * to one of `$registeredAcsUrls`?
     *
     * Deliberately uses `in_array(..., true)` (strict mode) — NO scheme/
     * host/case normalisation, NO trailing-slash tolerance, NO prefix or
     * substring matching, and NO dependence on the database's collation.
     * This is the authoritative check — {@see self::validateAcsUrl()} is a
     * thin DB wrapper around it.
     *
     * @param string   $candidate         Candidate ACS URL.
     * @param string[] $registeredAcsUrls The SP's registered allowlist.
     * @return bool
     */
    public static function isExactAcsMatch(string $candidate, array $registeredAcsUrls): bool
    {
        return in_array($candidate, $registeredAcsUrls, true);
    }

    /**
     * 🎯 Resolve the ACS URL to respond to for a given request.
     *
     * Per SAML 2.0 Core §3.4.1: if the AuthnRequest names an
     * AssertionConsumerServiceURL, it MUST byte-exactly match a registered
     * row (else this returns null — the caller's contract is to treat that
     * as a FATAL, non-redirectable error, exactly like an unregistered OIDC
     * redirect_uri). If the request omits it, the SP's `isDefault` row is
     * used; if no default is configured, null is returned (also fatal).
     *
     * @param array       $sp                Hydrated SP row (from getBySpID()/getByEntityID()).
     * @param string|null $requestedAcsUrl   The AuthnRequest's AssertionConsumerServiceURL, or null if omitted.
     * @return string|null The PROVEN ACS URL to use, or null if there is none safe to use.
     */
    public static function resolveAcsUrl(array $sp, ?string $requestedAcsUrl): ?string
    {
        $acsUrls = is_array($sp['acsUrls'] ?? null) ? $sp['acsUrls'] : [];

        if ($requestedAcsUrl !== null && $requestedAcsUrl !== '') {
            foreach ($acsUrls as $row) {
                if (($row['acsURL'] ?? null) === $requestedAcsUrl) {
                    return $requestedAcsUrl;
                }
            }
            return null; // Requested but NOT registered — fatal, no fallback.
        }

        foreach ($acsUrls as $row) {
            if (!empty($row['isDefault'])) {
                return (string) $row['acsURL'];
            }
        }

        return null; // No default configured — fatal.
    }

    /**
     * 🗺️ Validate an attributeMap: every key/value must be a non-empty
     * string, and every VALUE must be a claim name this codebase actually
     * knows how to resolve (the SAML attribute NAME on the left is
     * SP-chosen and unconstrained; the claim NAME on the right is not).
     *
     * @param array $attributeMap
     * @return bool
     */
    public static function validateAttributeMap(array $attributeMap): bool
    {
        if (empty($attributeMap)) {
            return true; // An empty map is valid — no AttributeStatement emitted.
        }

        foreach ($attributeMap as $samlAttributeName => $claimName) {
            if (!is_string($samlAttributeName) || trim($samlAttributeName) === '') {
                return false;
            }
            if (!is_string($claimName) || !in_array($claimName, self::VALID_CLAIM_NAMES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 📜 Structurally validate a candidate SP signing certificate PEM.
     *
     * Uses `openssl_x509_read()` — a syntactically-broken or non-certificate
     * PEM is rejected before it can ever become "the pinned verifier" for a
     * request signature check. Does NOT check expiry/trust chain (a
     * self-issued SP cert has no chain; expiry is checked at USE time by the
     * verification code path, not at registration time).
     *
     * @param string $certPem Candidate PEM.
     * @return bool
     */
    public static function validateCertificatePem(string $certPem): bool
    {
        if (trim($certPem) === '' || !str_contains($certPem, 'CERTIFICATE')) {
            return false;
        }

        $resource = @openssl_x509_read($certPem);

        return $resource !== false;
    }

    // ========================================================================
    // 🔧 ACS URL MANAGEMENT
    // ========================================================================

    /**
     * ➕ Add an ACS URL to an existing SP.
     *
     * @param int    $spID      Internal spID.
     * @param string $acsURL    Exact ACS URL to add.
     * @param bool   $isDefault Whether this becomes the new default (demotes any existing default).
     * @return bool Success.
     */
    public static function addAcsUrl(int $spID, string $acsURL, bool $isDefault = false): bool
    {
        $acsURL = trim($acsURL);
        if ($acsURL === '' || strlen($acsURL) > self::MAX_URL_LENGTH || !self::isValidUrlFormat($acsURL)) {
            return false;
        }

        Database::beginTransaction();
        try {
            if ($isDefault) {
                Database::query(
                    "UPDATE tblSAMLServiceProviderAcsUrls SET isDefault = 0 WHERE spID = ?",
                    [$spID],
                    'i'
                );
            }

            Database::query(
                "INSERT INTO tblSAMLServiceProviderAcsUrls (spID, acsURL, binding, isDefault)
                 VALUES (?, ?, 'HTTP-POST', ?)
                 ON DUPLICATE KEY UPDATE isDefault = VALUES(isDefault)",
                [$spID, $acsURL, $isDefault ? 1 : 0],
                'isi'
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            error_log('SamlServiceProviderManager::addAcsUrl — failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * ➖ Remove an ACS URL by its row id.
     *
     * @param int $acsUrlID
     * @return bool Success.
     */
    public static function removeAcsUrl(int $acsUrlID): bool
    {
        Database::query(
            "DELETE FROM tblSAMLServiceProviderAcsUrls WHERE acsUrlID = ?",
            [$acsUrlID],
            'i'
        );

        return Database::getAffectedRows() > 0;
    }

    /**
     * 📋 List the ACS URL rows for an SP.
     *
     * @param int $spID Internal spID.
     * @return array<int,array{acsUrlID:int,acsURL:string,binding:string,isDefault:bool}>
     */
    public static function listAcsUrls(int $spID): array
    {
        $rows = Database::fetchAll(
            "SELECT acsUrlID, acsURL, binding, isDefault FROM tblSAMLServiceProviderAcsUrls WHERE spID = ? ORDER BY acsUrlID ASC",
            [$spID],
            'i'
        );

        return array_map(static function (array $row): array {
            $row['isDefault'] = (bool) $row['isDefault'];
            return $row;
        }, $rows);
    }

    /**
     * 📋 List just the ACS URL strings for an SP (used by
     * {@see self::validateAcsUrl()}).
     *
     * @param int $spID Internal spID.
     * @return string[]
     */
    private static function listAcsUrlStrings(int $spID): array
    {
        $rows = Database::fetchAll(
            "SELECT acsURL FROM tblSAMLServiceProviderAcsUrls WHERE spID = ?",
            [$spID],
            'i'
        );

        return array_map(static fn(array $r): string => $r['acsURL'], $rows);
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🧹 Validate + dedupe a candidate ACS-URL list, preserving order.
     *
     * @param string[] $urls Raw candidate URLs.
     * @return string[] Valid, trimmed, deduped URLs (possibly empty).
     */
    private static function normaliseAcsUrls(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url === '' || in_array($url, $out, true)) {
                continue;
            }
            if (!self::isValidUrlFormat($url)) {
                continue;
            }
            $out[] = $url;
        }

        return $out;
    }

    /**
     * 🔗 Light format sanity-check at REGISTRATION time ONLY (rejects
     * obviously malformed entries — no scheme, whitespace, control
     * characters, over-length). This is NOT the security-relevant check —
     * that is the byte-exact match done by {@see self::isExactAcsMatch()}
     * at SSO time.
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    private static function isValidUrlFormat(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 🧱 Hydrate a raw tblSAMLServiceProviders row: attach its ACS-URL
     * allowlist, decode attributeMap JSON, and cast boolean-ish columns.
     *
     * @param array $row Raw DB row.
     * @return array Hydrated row.
     */
    private static function hydrate(array $row): array
    {
        $row['acsUrls'] = self::listAcsUrls((int) $row['spID']);

        $row['attributeMap'] = null;
        if (!empty($row['attributeMap']) && is_string($row['attributeMap'])) {
            $decoded = json_decode($row['attributeMap'], true);
            $row['attributeMap'] = is_array($decoded) ? $decoded : null;
        }

        $row['wantAuthnRequestsSigned'] = (bool) ($row['wantAuthnRequestsSigned'] ?? false);
        $row['signAssertion']           = (bool) ($row['signAssertion'] ?? false);
        $row['signResponse']            = (bool) ($row['signResponse'] ?? false);
        $row['wantAssertionsEncrypted'] = (bool) ($row['wantAssertionsEncrypted'] ?? false);
        $row['requireConsent']          = (bool) ($row['requireConsent'] ?? false);
        $row['isFirstParty']            = (bool) ($row['isFirstParty'] ?? false);
        $row['isActive']                = (bool) ($row['isActive'] ?? false);

        return $row;
    }

    /**
     * 📝 Best-effort activity-log write (never throws).
     *
     * @param string   $activityType Short machine-readable event name.
     * @param int|null $userID       Acting user, or null for system/unknown.
     * @param string   $description  Human-readable description.
     * @param array    $metadata     Extra structured context.
     * @return void
     */
    private static function logEvent(string $activityType, ?int $userID, string $description, array $metadata = []): void
    {
        if (class_exists('ActivityLogger')) {
            // 🏷️ Category 'api_keys' is the closest existing
            //    tblActivityLog.activityCategory ENUM member — mirrors
            //    OAuthClientManager::logEvent()'s own precedent/rationale
            //    (SP registration is the same semantic class of event as
            //    API-key/OAuth-client credential management). No dedicated
            //    'saml' ENUM member exists yet; flagged as a
            //    NEEDS-LEAD-REVIEW nit for a future migration if a
            //    dedicated category is wanted.
            ActivityLogger::log($userID, $activityType, 'api_keys', 'info', $description, $metadata);
        }
    }
}

// ✅ SamlServiceProviderManager loaded successfully
