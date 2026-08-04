-- ============================================================================
-- SIGNula Database Migration 050
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🪪 SIGNula - SAML 2.0 Identity-Provider: dormant foundation (G-001 Phase B, #100)
-- ============================================================================
--
-- Migration: 050_saml_idp.sql
-- Feature:   G-001 "Phase B" — SIGNula as a SAML 2.0 Web Browser SSO Identity
--            Provider, for enterprise Service Providers (SPs) that speak SAML
--            rather than OIDC. Builds on the already-shipped OIDC/OAuth2
--            provider (migrations 031-035, issues #87/#89/#90) and mirrors it
--            table-for-table, per the deep design plan at issue #100:
--
--              OIDC provider (built)                    | SAML IdP (this migration)
--              ------------------------------------------+------------------------------------
--              tblOAuthClients                            | tblSAMLServiceProviders
--              tblOAuthClientRedirectUris (exact-match)   | tblSAMLServiceProviderAcsUrls (exact-match)
--              tblOAuthAuthCodes (pending, single-use)    | tblSAMLAuthnRequests (pending, single-use)
--              tblOAuthConsents                           | tblSAMLConsents
--              tblOAuthAccessGrants (issued ledger)       | tblSAMLAssertions (issued ledger)
--              oidc.* settings + oidc.enabled gate        | saml.* settings + saml.enabled gate
--
-- 🚦 CRITICAL — SHIPS DORMANT, NOT PRODUCTION-READY:
--   `saml.enabled` is seeded '0' (master OFF switch — the SAME
--   filter_var(FILTER_VALIDATE_BOOLEAN) gate pattern as `oidc.enabled`,
--   migration 031, enforced by every `/saml/*` controller via
--   SamlMetadataService::isSamlEnabled()). Every `tblSAMLServiceProviders`
--   row this migration or any future admin action creates ALSO defaults
--   `isActive = 0`. Flipping `saml.enabled` to '1' is a deliberate, HUMAN,
--   ops decision that must not happen until:
--     (a) staging interop is verified against ≥2 independent SP stacks
--         (SimpleSAMLphp + samltest.id/mocksaml.com/an Entra ID or Okta dev
--         tenant), and
--     (b) a red-team pass covers XML-Signature-Wrapping (XSW), XXE/DTD,
--         DEFLATE-bomb, replay, ACS-bypass, and SigAlg-downgrade vectors —
--   see PRE_LAUNCH_REVIEW.md §7 and the plan's §9/§10 (Gates 2-4). Zero
--   existing behaviour changes while `saml.enabled='0'` — this migration is
--   purely additive (new tables + new `saml.*` settings only).
--
-- 📝 What this migration adds:
--
--   1. tblSAMLServiceProviders           — registered SAML 2.0 Service
--                                           Providers (the SAML analogue of
--                                           tblOAuthClients). isActive
--                                           DEFAULTs to 0 (dormant-per-row,
--                                           on top of the global saml.enabled
--                                           gate — belt and braces).
--   2. tblSAMLServiceProviderAcsUrls     — the EXACT-MATCH
--                                           AssertionConsumerServiceURL (ACS)
--                                           allowlist per SP (the #1 SAML IdP
--                                           security control — the redirect_uri
--                                           allowlist rule transplanted from
--                                           OIDC). `utf8mb4_bin` collation is
--                                           DB-level defence-in-depth; the
--                                           AUTHORITATIVE check is the PHP
--                                           strict (===) comparison in
--                                           SamlServiceProviderManager::
--                                           isExactAcsMatch() — correctness
--                                           never depends on DB collation.
--   3. tblSAMLAuthnRequests              — pending AuthnRequests, surviving
--                                           the login/MFA redirect round-trip.
--                                           Single-use (atomic `consumedAt`),
--                                           short TTL (saml.authnrequest_ttl).
--   4. tblSAMLAssertions                 — issued-assertion ledger (audit +
--                                           SLO SessionIndex correlation +
--                                           replay evidence). UNIQUE on the
--                                           XML assertion ID forever.
--   5. tblSAMLConsents                   — per-user, per-SP attribute-release
--                                           consent (privacy-first parity with
--                                           tblOAuthConsents / the "Connected
--                                           Apps" hub).
--   6. tblSettings rows (category 'saml')— IdP policy (master switch, TTLs,
--                                           clock skew, algorithm allowlist-
--                                           of-one, DEFLATE-bomb byte cap).
--                                           The SAML signing keypair/cert
--                                           (saml.signing_key.<kid>.*) is
--                                           DELIBERATELY NOT seeded here —
--                                           minted at runtime by
--                                           SamlKeyManager::generateKey() on
--                                           first use, exactly the
--                                           jwt.signing_key.* (migration 030)
--                                           / oauth.pairwise_salt (migration
--                                           031 header note) precedent.
--
-- 🔗 Design source: the SAML 2.0 Identity-Provider deep design plan for issue
--    #100 ("SAML 2.0 Identity-Provider Profile for SIGNula.id"), which itself
--    realises `.dev-team/specs/G-001.md` §2 Phase B / §3.B / §4 / §5.8 /
--    §5.10 / §6 / §8 Stage B. DELIBERATE, load-bearing corrections/decisions
--    vs. the G-001.md §4 Phase-B one-liner sketch (logged here + in the PR,
--    mirroring how migration 031 logged its own corrections vs. G-001.md):
--
--     • Migration NUMBER is 050 — 049 is the current highest
--       (049_generic_oidc_provider.sql, merged concurrently with this work;
--       048_enable_launch_features.sql was the highest at plan time).
--     • FK id columns are BIGINT UNSIGNED throughout, matching
--       tblPartners.partnerID / tblUsers.userID (confirmed against
--       008_partner_api_keys.sql and 031_oauth_provider_clients.sql) — a
--       FOREIGN KEY column must match the referenced column's exact type or
--       MySQL rejects it (errno 3780/150).
--     • tblSAMLServiceProviderAcsUrls is a SEPARATE table, not a JSON column
--       on tblSAMLServiceProviders — the SAME correction migration 031 made
--       for OAuth redirect URIs (a JSON column cannot carry a per-row
--       `utf8mb4_bin` collation or a clean UNIQUE(spID, acsURL) constraint).
--     • tblSAMLAuthnRequests is ADDED (the G-001.md §4 sketch has no
--       pending-request store) — needed so an AuthnRequest can survive the
--       `/login?redirect=…` + MFA round-trip without ever trusting
--       client-side state for anything security-relevant (mirrors
--       tblOAuthAuthCodes' role for the OIDC authorization-code flow).
--     • tblSAMLConsents is ADDED (house privacy-first consistency with the
--       OIDC consent screen / tblOAuthConsents) — the G-001.md §4 sketch does
--       not mention SAML attribute-release consent at all.
--     • Column/table naming uses this codebase's established "<entity>ID" PK
--       convention (spID, acsUrlID, pendingID, ledgerID, consentID) rather
--       than a generic `id`, matching every prior migration.
--
-- ⚙️ Applies via the CLI runner (_scripts/setup_test_db.sh loops 014.. and
--    does `mysql DB < file`) and the admin migration UI. Uses NO `DELIMITER`
--    directive (no compound-statement bodies below) and NO `USE <db>;` (a
--    hardcoded USE would mis-target the throwaway signula_test database —
--    see 030/031's header notes and the setup script's step 3️⃣ stripping
--    comment).
--
-- 🛡️ Idempotent + additive + non-destructive: CREATE TABLE IF NOT EXISTS,
--    INSERT IGNORE for settings. Safe to re-run. Does NOT self-write to
--    tblMigrations (the migration runner owns that bookkeeping).
--
-- @see .dev-team/specs/G-001.md §2 Phase B, §3.B, §4, §5.8, §5.10, §6, §8 Stage B
-- @see _database/migrations/031_oauth_provider_clients.sql (the table-for-table
--      precedent this migration mirrors, incl. its own corrections log)
-- @see _database/migrations/046_flexible_pricing_catalog.sql /
--      048_enable_launch_features.sql (the "ship dormant, isActive=0,
--      enable later via a deliberate ops step" precedent)
-- @see web/private_html/auth/SamlServiceProviderManager.php (this migration's manager class)
-- @see web/private_html/auth/SamlMetadataService.php (isSamlEnabled() gate)
-- @see web/private_html/security/SamlKeyManager.php (saml.signing_key.* lifecycle)
-- @see https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf (SAML 2.0 Core)
-- @see https://docs.oasis-open.org/security/saml/v2.0/saml-bindings-2.0-os.pdf (SAML 2.0 Bindings)
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ Registered SAML 2.0 Service Providers (parallels tblOAuthClients)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tblSAMLServiceProviders (
    spID                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    -- 🏢 Ownership — NULL means a first-party/global SIGNula-owned SP.
    partnerID               BIGINT UNSIGNED NULL COMMENT 'Owning partner; NULL = first-party/global',

    -- 🆔 SP identity — the byte-exact match key for the inbound <Issuer> AND
    --    the value asserted back as <AudienceRestriction><Audience>.
    entityID                VARCHAR(500) NOT NULL COLLATE utf8mb4_bin
                            COMMENT 'SP entityID -- byte-exact match key for inbound <Issuer>; also the Audience value',
    displayName             VARCHAR(150) NOT NULL COMMENT 'Shown on the attribute-release consent screen',

    -- 🧾 NameID policy — what SIGNula asserts as the Subject's <NameID>.
    nameIDFormat            ENUM('emailAddress', 'persistent', 'transient', 'unspecified')
                            NOT NULL DEFAULT 'emailAddress'
                            COMMENT 'urn:oasis:names:tc:SAML:...:nameid-format:<value> asserted in the Subject',

    -- 🗺️ Attribute release: JSON map {"samlAttributeName": "signulaClaim", ...}.
    --    The claim vocabulary reuses the SAME claim names IdTokenBuilder /
    --    OAuthUserInfoService already emit for OIDC (email, email_verified,
    --    name, given_name, family_name, preferred_username, picture) so a
    --    single, already-tested claim-resolution codepath backs both
    --    protocols. NULL = no AttributeStatement is emitted.
    attributeMap            JSON NULL COMMENT 'SAML attribute name -> SIGNula claim name map',

    -- 🔏 Per-SP signing/verification policy.
    spSigningCertPEM        TEXT NULL
                            COMMENT 'SP X.509 cert (PEM) -- the ONLY verifier for signed requests; any KeyInfo embedded in a message is IGNORED',
    wantAuthnRequestsSigned BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Require + verify request signatures (C1 Redirect / C3 POST)',
    signAssertion           BOOLEAN NOT NULL DEFAULT 1 COMMENT 'Sign the <Assertion> (XML-DSig, B1)',
    signResponse            BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Some SPs (e.g. AWS) additionally require the <Response> itself signed',
    wantAssertionsEncrypted BOOLEAN NOT NULL DEFAULT 0
                            COMMENT 'XML-Enc (v2, out of scope) -- v1 REFUSES to activate an SP with this set to 1',

    -- 🚪 Single Logout (optional).
    sloURL                  VARCHAR(500) NULL COLLATE utf8mb4_bin COMMENT 'SP SingleLogoutService endpoint',
    sloBinding              ENUM('HTTP-Redirect', 'HTTP-POST') NULL DEFAULT 'HTTP-Redirect',

    -- ⏱️ Per-SP policy overrides.
    assertionTTLSeconds     INT UNSIGNED NOT NULL DEFAULT 300 COMMENT 'Assertion NotOnOrAfter horizon in seconds',
    requireConsent          BOOLEAN NOT NULL DEFAULT 1 COMMENT 'Show the attribute-release consent screen (isFirstParty may waive)',
    isFirstParty            BOOLEAN NOT NULL DEFAULT 0 COMMENT 'First-party SPs MAY skip consent (consent is still recorded)',

    -- 🚦 DORMANT BY DEFAULT -- mirrors the isActive=0 catalog-row precedent
    --    (migration 046). A newly-registered SP never goes live until BOTH
    --    this flag AND the global saml.enabled switch are explicitly on.
    isActive                BOOLEAN NOT NULL DEFAULT 0,

    -- 📝 Audit.
    createdBy               BIGINT UNSIGNED NULL COMMENT 'userID who registered this SP (partner-admin or global admin)',
    createdAt               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    FOREIGN KEY (createdBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,
    UNIQUE KEY uk_entity_id (entityID(191)),
    INDEX idx_partner (partnerID),
    INDEX idx_active (isActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Registered SAML 2.0 Service Providers -- G-001 Phase B (#100), dormant by default';

-- ============================================================================
-- 2️⃣ ACS URL EXACT-MATCH allowlist (one SP -> many AssertionConsumerServiceURLs)
-- ============================================================================
-- The #1 SAML IdP security control (the redirect_uri allowlist rule
-- transplanted from OIDC): the AssertionConsumerServiceURL an inbound
-- AuthnRequest names (or the SP's default ACS if the request omits it) MUST
-- byte-exactly match a row here BEFORE any Response is ever emitted toward
-- it. No prefix/substring/wildcard matching anywhere.
CREATE TABLE IF NOT EXISTS tblSAMLServiceProviderAcsUrls (
    acsUrlID   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    spID       BIGINT UNSIGNED NOT NULL,

    -- 🎯 utf8mb4_bin (byte/case-sensitive) as DB-level defence-in-depth so a
    --    collation quirk can never introduce a case-insensitive bypass of the
    --    "exact match" rule. The AUTHORITATIVE check still happens in PHP via
    --    a strict (===) comparison -- see
    --    SamlServiceProviderManager::isExactAcsMatch().
    acsURL     VARCHAR(500) NOT NULL COLLATE utf8mb4_bin
              COMMENT 'EXACT-match AssertionConsumerServiceURL -- full string, no wildcards, no normalisation',
    binding    ENUM('HTTP-POST') NOT NULL DEFAULT 'HTTP-POST' COMMENT 'v1: Responses are delivered via HTTP-POST binding only',
    isDefault  BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Used when the AuthnRequest omits AssertionConsumerServiceURL',
    createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (spID) REFERENCES tblSAMLServiceProviders(spID) ON DELETE CASCADE,
    UNIQUE KEY uk_sp_acs (spID, acsURL),
    INDEX idx_sp (spID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-SP EXACT-match AssertionConsumerServiceURL allowlist (no wildcards) -- G-001 Phase B (#100)';

-- ============================================================================
-- 3️⃣ Pending AuthnRequests (parallels tblOAuthAuthCodes: survives the
--    login/MFA round-trip, single-use, short TTL, atomic consumedAt)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tblSAMLAuthnRequests (
    pendingID             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    handleHash            CHAR(64) NOT NULL COMMENT 'SHA-256 of the random resume handle round-tripped through /login -- plaintext never stored',
    spID                  BIGINT UNSIGNED NOT NULL,
    requestID             VARCHAR(255) NOT NULL COMMENT 'The SP AuthnRequest @ID -- echoed back as <Response InResponseTo> (not itself secret)',
    acsURL                VARCHAR(500) NOT NULL COLLATE utf8mb4_bin COMMENT 'Resolved + allowlist-proven ACS destination for this request',
    relayState            VARCHAR(1024) NULL COMMENT 'Opaque; echoed verbatim, NEVER interpreted; spec caps at 80 bytes, stored longer defensively',
    forceAuthn            BOOLEAN NOT NULL DEFAULT 0,
    isPassive             BOOLEAN NOT NULL DEFAULT 0,
    requestedNameIDFormat VARCHAR(255) NULL COMMENT 'NameIDPolicy/@Format requested by the SP, if any',
    expiresAt             DATETIME NOT NULL COMMENT 'saml.authnrequest_ttl seconds from issuance (default 600s)',
    consumedAt            DATETIME NULL COMMENT 'Set atomically on first (and only) consumption -- a second consume attempt is a REPLAY',
    ipAddress             VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 of the issuing request',
    createdAt             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (spID) REFERENCES tblSAMLServiceProviders(spID) ON DELETE CASCADE,
    UNIQUE KEY uk_handle (handleHash),
    INDEX idx_expires (expiresAt),
    INDEX idx_sp (spID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Single-use pending SAML AuthnRequests (short TTL, survives login/MFA redirect) -- G-001 Phase B (#100)';

-- ============================================================================
-- 4️⃣ Issued-assertion ledger (parallels tblOAuthAccessGrants; audit + SLO +
--    replay evidence)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tblSAMLAssertions (
    ledgerID        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    samlAssertionID VARCHAR(64) NOT NULL COMMENT 'The XML ID (NCName: "_" + 160-bit hex) -- unique forever, never reused',
    spID            BIGINT UNSIGNED NOT NULL,
    userID          BIGINT UNSIGNED NOT NULL,
    inResponseTo    VARCHAR(255) NULL COMMENT 'NULL would mean IdP-initiated (disabled in v1 -- saml.idp_initiated_enabled=0)',
    sessionIndex    VARCHAR(64) NOT NULL COMMENT 'Ties this assertion to a SIGNula session, for SLO correlation',
    nameIDValue     VARCHAR(255) NOT NULL COMMENT 'What was asserted (email / persistent pairwise hash / transient value)',
    issuedAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notOnOrAfter    DATETIME NOT NULL COMMENT 'Assertion + SubjectConfirmationData NotOnOrAfter',
    ipAddress       VARCHAR(45) NULL,

    FOREIGN KEY (spID)   REFERENCES tblSAMLServiceProviders(spID) ON DELETE CASCADE,
    FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE,
    UNIQUE KEY uk_assertion_id (samlAssertionID),
    INDEX idx_user_sp (userID, spID),
    INDEX idx_session (sessionIndex)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ledger of SAML assertions issued to SPs (audit + SLO correlation + replay evidence) -- G-001 Phase B (#100)';

-- ============================================================================
-- 5️⃣ Attribute-release consent (parallels tblOAuthConsents; Connected-Apps parity)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tblSAMLConsents (
    consentID  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    userID     BIGINT UNSIGNED NOT NULL,
    spID       BIGINT UNSIGNED NOT NULL,
    attributes VARCHAR(1024) NOT NULL COMMENT 'Space-delimited released attribute names the user approved',
    grantedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revokedAt  DATETIME NULL COMMENT 'Set when the user revokes this SP from the "Connected Apps" hub',
    ipAddress  VARCHAR(45) NULL,

    FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE,
    FOREIGN KEY (spID)   REFERENCES tblSAMLServiceProviders(spID) ON DELETE CASCADE,
    UNIQUE KEY uk_user_sp (userID, spID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-user, per-SP attribute-release consent records (auditable; user-revocable) -- G-001 Phase B (#100)';

-- ============================================================================
-- 6️⃣ Settings: SAML IdP policy (all non-sensitive; INSERT IGNORE)
-- ============================================================================
-- 🔑 saml.signing_key.<kid>.private_pem / .certificate_pem / active_kid are
--    DELIBERATELY NOT SEEDED HERE. Exactly like G-003's
--    jwt.signing_key.<kid>.private_pem (migration 030) and G-001's
--    oauth.pairwise_salt (migration 031 header note), a secret keypair is
--    minted at RUNTIME, on first use, by SamlKeyManager::generateKey() and
--    stored isSensitive=1 (encrypted via SecurityUtils::encrypt()) for the
--    private key, in the clear for the public X.509 certificate (which is,
--    by design, published in IdP metadata).
--
-- Column list uses the REAL tblSettings columns (settingCategory, not
-- "category"; description, not "settingDescription" -- see migration 030's
-- header note about this schema's inconsistent column names across older
-- migrations):
--   (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
INSERT IGNORE INTO tblSettings
    (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
VALUES
    ('saml.enabled',                      '0',                                        'boolean', 0, 'saml', 'MASTER DORMANT SWITCH for the SAML 2.0 Identity-Provider surface -- default OFF. Do NOT enable until staging interop (>=2 independent SP stacks) + a red-team pass (XSW/XXE/replay/ACS-bypass/SigAlg-downgrade) are evidenced -- see PRE_LAUNCH_REVIEW.md.'),
    ('saml.idp_entity_id',                'https://signula.id/saml/metadata',         'string',  0, 'saml', 'SAML IdP entityID -- published in metadata as <EntityDescriptor entityID="...">. MUST stay consistent with oidc.issuer / jwt.issuer host.'),
    ('saml.assertion_ttl',                '300',                                       'integer', 0, 'saml', 'Default <Assertion>/<SubjectConfirmationData> NotOnOrAfter horizon, in seconds (per-SP assertionTTLSeconds can override).'),
    ('saml.clock_skew',                   '120',                                       'integer', 0, 'saml', 'NotBefore/NotOnOrAfter + IssueInstant clock-skew leeway, in seconds (mirrors the Jwt leeway concept).'),
    ('saml.authnrequest_ttl',             '600',                                       'integer', 0, 'saml', 'Lifetime of a pending tblSAMLAuthnRequests row, in seconds, before it expires unconsumed.'),
    ('saml.signature_algorithm',          'rsa-sha256',                                'string',  0, 'saml', 'XML-DSig SignatureMethod -- an ALLOWLIST OF ONE. SHA-1/DSA/HMAC signature URIs are always rejected regardless of this setting.'),
    ('saml.digest_algorithm',             'sha256',                                    'string',  0, 'saml', 'XML-DSig Reference DigestMethod -- an ALLOWLIST OF ONE. SHA-1 digest URIs are always rejected regardless of this setting.'),
    ('saml.require_signed_authnrequests', '0',                                         'boolean', 0, 'saml', 'Global floor for wantAuthnRequestsSigned -- a per-SP flag may raise this to 1, never lower it below this floor.'),
    ('saml.idp_initiated_enabled',        '0',                                         'boolean', 0, 'saml', 'Unsolicited (IdP-initiated) SSO responses -- OFF in v1. Unsolicited responses have no InResponseTo, weakening replay defence.'),
    ('saml.max_inflated_request_bytes',   '1048576',                                   'integer', 0, 'saml', 'DEFLATE-bomb guard: hard cap, in bytes, on the INFLATED size of a decoded SAMLRequest/SAMLResponse payload.');
-- saml.signing_key.<kid>.private_pem / .certificate_pem / active_kid
-- (isSensitive=1 for the private key) -- created at runtime by
-- SamlKeyManager::generateKey() on first use.

-- ============================================================================
-- 7️⃣ Verification queries
-- ============================================================================
SELECT 'SAML IdP dormant foundation (G-001 Phase B, #100) tables created' AS Status,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblSAMLServiceProviders')        AS tblSAMLServiceProviders_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblSAMLServiceProviderAcsUrls')  AS tblSAMLServiceProviderAcsUrls_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblSAMLAuthnRequests')           AS tblSAMLAuthnRequests_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblSAMLAssertions')              AS tblSAMLAssertions_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblSAMLConsents')                AS tblSAMLConsents_exists;

SELECT 'SAML IdP policy settings seeded' AS Status,
    COUNT(*) AS saml_settings_count
FROM tblSettings WHERE settingKey LIKE 'saml.%';

SELECT 'saml.enabled master switch value (MUST read 0 immediately after this migration)' AS Status,
    settingValue AS saml_enabled_value
FROM tblSettings WHERE settingKey = 'saml.enabled';

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 050_saml_idp.sql completed successfully -- SAML IdP surface remains DORMANT (saml.enabled=0)' AS Result;
