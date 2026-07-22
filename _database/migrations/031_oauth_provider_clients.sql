-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================

-- ============================================================================
-- 🪪 SIGNula - OAuth2/OIDC Provider: Client Registration (G-001 Stage A1)
-- ============================================================================
--
-- Migration: 031_oauth_provider_clients.sql
-- Feature:   G-001 (#87 / FG-001) — SIGNula as an OAuth2/OIDC PROVIDER
--            ("Sign in with SIGNula.id"). Stage A1 ONLY: the client-registration
--            data model. NO /oauth/authorize or /oauth/token endpoints yet —
--            those are Stage A2/A3 and populate tblOAuthAuthCodes /
--            tblOAuthConsents / tblOAuthAccessGrants respectively.
-- Version:   2.9.0-beta
-- Date:      2026-07-10
--
-- 📝 What this migration adds:
--
--   1. tblOAuthClients             — registered Relying-Party (RP) OAuth/OIDC
--                                     clients ("Sign in with SIGNula.id" apps).
--   2. tblOAuthClientRedirectUris  — the EXACT-MATCH redirect_uri allowlist for
--                                     each client (a separate table so one client
--                                     can register several exact URIs — e.g. a
--                                     web callback AND a native custom-scheme
--                                     deep link — with no wildcard/prefix logic
--                                     anywhere in the system).
--   3. tblOAuthAuthCodes           — single-use, PKCE-bound authorization codes
--                                     (table only in A1; Stage A2 populates it).
--   4. tblOAuthConsents            — per-user, per-client remembered consent
--                                     (table only in A1; Stage A2 populates it).
--   5. tblOAuthAccessGrants        — the issued-token ledger / revocation surface
--                                     for tokens minted on behalf of an RP
--                                     (table only in A1; Stage A3 populates it).
--   6. tblSettings rows            — OIDC provider policy (issuer/TTLs/PKCE
--                                     policy/consent policy). The pairwise-
--                                     subject SALT (oauth.pairwise_salt) is
--                                     deliberately NOT seeded here — see §5 below
--                                     (mirrors how G-003's jwt.signing_key.* rows
--                                     are minted at runtime, not hardcoded in a
--                                     migration).
--
-- 🔗 Design source: .dev-team/specs/G-001.md §4 (data-model) and
--    .dev-team/specs/G-001-integration-plan.md §2 "A1" (the refined build
--    contract). This migration follows those documents with the following
--    DELIBERATE, load-bearing corrections/decisions (logged here + in the PR):
--
--     • Migration NUMBER is 031, not the provider spec's stale "026" (026 is
--       already 026_user_mfa_columns.sql). 030 is the current highest
--       (030_jwt_authentication.sql — G-003), so 031 is next. Matches the
--       integration plan's §0 "Migration number" correction.
--     • FK id columns are BIGINT UNSIGNED throughout (not the provider spec
--       draft's "INT UNSIGNED"): tblPartners.partnerID and tblUsers.userID are
--       BOTH BIGINT UNSIGNED (confirmed against 008_partner_api_keys.sql and
--       030_jwt_authentication.sql), and a FOREIGN KEY column MUST match the
--       referenced column's exact type or MySQL rejects it (errno 3780/150).
--     • tblOAuthClientRedirectUris is a SEPARATE table, not the provider spec
--       draft's single JSON `redirectURIs` column on tblOAuthClients — per the
--       integration plan's explicit build contract for A1 ("a separate table so
--       a client can have multiple exact URIs — web + native custom-scheme").
--       The `redirectURI` column uses the `utf8mb4_bin` collation (case- and
--       byte-sensitive) as defence-in-depth against a collation-level
--       case-insensitive bypass of the "exact match" rule — but the
--       AUTHORITATIVE match is done in PHP with a strict (`===`) comparison in
--       OAuthClientManager::isExactRedirectMatch(), so correctness never
--       depends on the DB's collation configuration.
--     • Column/table naming uses this codebase's established "<entity>ID" PK
--       convention (clientID, redirectUriID, codeID, consentID, grantID) rather
--       than a generic `id`, matching every prior migration (partnerID, keyID,
--       tokenID, revokeID, tierID, …) — the G-001 spec's own draft SQL already
--       does this for tblOAuthClients/tblOAuthAuthCodes/etc., this migration
--       keeps that convention consistent on the new redirect-URI table too.
--     • `oidc.issuer` is seeded to the SAME value as G-003's `jwt.issuer`
--       (`https://signula.id`) — required so a future id_token's `iss` claim
--       and the OIDC discovery document's `issuer` never drift apart (the
--       integration plan's GOTCHA-3 note).
--     • Three EXTRA settings the integration-plan §2 "A1" bullet calls for ahead
--       of Stage A3 so no follow-up migration is needed purely for policy:
--       `oidc.access_ttl` (900s), `oidc.id_token_ttl` (3600s),
--       `oidc.refresh_enabled` (1).
--     • `tblOAuthClients.subjectType` DEFAULTs to 'pairwise' at the PER-CLIENT
--       column level (privacy-preserving default for new registrations, per
--       this task's explicit build contract), while the GLOBAL advisory
--       setting `oidc.subject_type` is seeded '`public`' (verbatim per
--       G-001.md §4's draft SQL). These are DELIBERATELY not forced to match —
--       flagged in the Stage A1 PR as a NEEDS-LEAD-REVIEW item: confirm which
--       should govern client registration defaults in the Stage A2+ admin UI.
--
-- ⚙️ Applies via the CLI runner (_scripts/setup_test_db.sh loops 014.. and does
--    `mysql DB < file`) and the admin migration UI. It uses NO `DELIMITER`
--    directive (no compound-statement bodies below) and NO `USE <db>;` (a
--    hardcoded USE would mis-target the throwaway signula_test database — see
--    030's header note and the setup script's step 3️⃣ stripping comment).
--
-- 🛡️ Idempotent + additive + non-destructive: CREATE TABLE IF NOT EXISTS,
--    INSERT IGNORE for settings. Safe to re-run. Does NOT self-write to
--    tblMigrations (the migration runner owns that bookkeeping — mirrors the
--    008/030 [mig-fix] note).
--
-- @see .dev-team/specs/G-001.md §4, §5
-- @see .dev-team/specs/G-001-integration-plan.md §2 "A1", §7
-- @see web/private_html/auth/OAuthClientManager.php (Stage A1 manager class)
-- @see _database/migrations/008_partner_api_keys.sql (tblAPIKeys — the
--      client_secret hashing + partner-ownership precedent)
-- @see _database/migrations/030_jwt_authentication.sql (recent-migration style
--      precedent; tblRefreshTokens.clientID is RESERVED for this table)
-- ============================================================================

-- (No `USE <db>;` — see header note above.)

-- ============================================================================
-- 1️⃣ Registered RP clients ("Sign in with SIGNula.id" apps)
-- ============================================================================
-- Owned by a partner (nullable — NULL means a first-party/global SIGNula-owned
-- client, e.g. a future SIGNula-operated app, that has no partner organisation).
-- The public `clientIdentifier` is what an RP calls `client_id`; the
-- `clientSecretHash` is the SHA-256 hash of the plaintext secret shown ONCE at
-- registration/rotation — exactly the tblAPIKeys.keyHash precedent (008).
CREATE TABLE IF NOT EXISTS tblOAuthClients (
    clientID            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    -- 🆔 Public identity (what the RP calls `client_id`)
    clientIdentifier    CHAR(32) NOT NULL COMMENT 'Public client_id — random 16-byte hex, non-guessable',
    clientSecretHash    CHAR(64) NULL COMMENT 'SHA-256 hex of client_secret; plaintext shown ONCE, never stored; NULL for public clients',
    clientName          VARCHAR(150) NOT NULL COMMENT 'Human-readable name shown on the consent screen',
    clientType          ENUM('confidential', 'public') NOT NULL DEFAULT 'confidential'
                         COMMENT 'confidential = server-side RP (has a secret); public = SPA/native/mobile (PKCE only, no usable secret)',

    -- 🏢 Ownership
    partnerID           BIGINT UNSIGNED NULL COMMENT 'Owning partner (RP); NULL = first-party/global SIGNula-owned client',
    isFirstParty        BOOLEAN NOT NULL DEFAULT 0 COMMENT 'First-party apps MAY skip the consent screen (consent is still recorded)',

    -- 🔐 OAuth/OIDC policy for this client
    pkceRequired        BOOLEAN NOT NULL DEFAULT 1 COMMENT 'Always 1 for public clients; recommended 1 for all (oidc.require_pkce)',
    allowedScopes       VARCHAR(512) NOT NULL COMMENT 'Space-delimited scopes this client may request, e.g. "openid profile email offline_access"',
    grantTypes          VARCHAR(255) NOT NULL DEFAULT 'authorization_code refresh_token'
                         COMMENT 'Space-delimited allowed grants, e.g. "authorization_code refresh_token"',

    -- 🕵️ Pairwise-subject support (privacy: a per-RP opaque `sub` so colluding
    --    RPs cannot correlate the same user via a shared raw userID).
    subjectType         ENUM('public', 'pairwise') NOT NULL DEFAULT 'pairwise'
                         COMMENT 'public = raw userID as sub; pairwise = per-sector opaque sub (OAuthClientManager::computeSubject)',
    sectorIdentifier     VARCHAR(255) NULL COMMENT 'Host (or client-scoped fallback) used to group pairwise sub values for this client',

    -- 🖼️ Consent-screen / branding metadata
    logoURI             VARCHAR(500) NULL COMMENT 'Shown on the consent screen (Stage A2)',
    clientURI            VARCHAR(500) NULL COMMENT 'RP home page, shown on the consent screen',
    tosURI               VARCHAR(500) NULL COMMENT 'RP Terms of Service link, shown on the consent screen',
    policyURI            VARCHAR(500) NULL COMMENT 'RP Privacy Policy link, shown on the consent screen',

    -- 🚦 Status
    isActive            BOOLEAN NOT NULL DEFAULT 1,

    -- 📝 Audit
    createdBy           BIGINT UNSIGNED NULL COMMENT 'userID who registered this client (partner-admin or global admin)',
    createdAt           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    FOREIGN KEY (createdBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,
    UNIQUE KEY uk_client_identifier (clientIdentifier),
    INDEX idx_partner (partnerID),
    INDEX idx_active (isActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Registered OAuth2/OIDC Relying-Party clients ("Sign in with SIGNula.id" apps) — G-001';

-- ============================================================================
-- 2️⃣ Redirect-URI EXACT-MATCH allowlist (one client → many URIs)
-- ============================================================================
-- The #1 OAuth IdP security control (G-001.md §5.1): the redirect_uri supplied
-- at /authorize AND /token must byte-exactly match one row here. No
-- prefix/substring/wildcard matching anywhere. A separate table (rather than a
-- JSON column) lets a single client register several exact URIs — e.g. a web
-- callback AND a native app's custom-scheme deep link — while keeping each one
-- independently indexable/uniquely-constrained.
CREATE TABLE IF NOT EXISTS tblOAuthClientRedirectUris (
    redirectUriID   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clientID        BIGINT UNSIGNED NOT NULL,

    -- 🎯 utf8mb4_bin (byte/case-sensitive) as DB-level defence-in-depth so a
    --    collation quirk can never introduce a case-insensitive bypass of the
    --    "exact match" rule. The AUTHORITATIVE check still happens in PHP via a
    --    strict (===) comparison — see OAuthClientManager::isExactRedirectMatch().
    redirectURI     VARCHAR(500) NOT NULL COLLATE utf8mb4_bin
                    COMMENT 'EXACT-match redirect_uri — full string, no wildcards, no normalisation',

    createdAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (clientID) REFERENCES tblOAuthClients(clientID) ON DELETE CASCADE,
    UNIQUE KEY uk_client_redirect (clientID, redirectURI),
    INDEX idx_client (clientID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-client EXACT-match redirect_uri allowlist (no wildcards) — G-001';

-- ============================================================================
-- 3️⃣ Authorization codes (single-use, short TTL, PKCE-bound)
-- ============================================================================
-- 🚧 TABLE ONLY in Stage A1 — no code is issued or consumed until Stage A2's
--    /oauth/authorize-idp + AuthCodeService exist. Defined now so the schema is
--    stable and testable ahead of that stage.
CREATE TABLE IF NOT EXISTS tblOAuthAuthCodes (
    codeID              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    codeHash            CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the issued code — plaintext never stored (mirrors tblAPIKeys.keyHash)',
    clientID            BIGINT UNSIGNED NOT NULL,
    userID              BIGINT UNSIGNED NOT NULL,
    redirectURI         VARCHAR(500) NOT NULL COLLATE utf8mb4_bin COMMENT 'Must match exactly at token exchange',
    scope               VARCHAR(512) NOT NULL COMMENT 'Space-delimited scopes granted for this code',
    codeChallenge       VARCHAR(128) NULL COMMENT 'PKCE code_challenge (base64url SHA-256 of the verifier)',
    codeChallengeMethod ENUM('S256', 'plain') NULL COMMENT 'S256 only accepted in prod (oidc.allow_plain_pkce)',
    nonce               VARCHAR(255) NULL COMMENT 'OIDC nonce — echoed into the id_token (replay defence)',
    authTime            DATETIME NOT NULL COMMENT 'When the user authenticated — becomes the id_token auth_time claim',
    expiresAt           DATETIME NOT NULL COMMENT 'Short TTL (oidc.authcode_ttl, default 60s)',
    consumedAt          DATETIME NULL COMMENT 'Set atomically on first exchange — a second use is a REPLAY (RFC 6749 §4.1.2/§10.5)',
    ipAddress           VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 of the issuing request',
    createdAt           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (clientID) REFERENCES tblOAuthClients(clientID) ON DELETE CASCADE,
    FOREIGN KEY (userID)   REFERENCES tblUsers(userID) ON DELETE CASCADE,
    UNIQUE KEY uk_code_hash (codeHash),
    INDEX idx_expires (expiresAt),
    INDEX idx_client (clientID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Single-use OAuth authorization codes (PKCE-bound, short TTL) — G-001 (populated from Stage A2)';

-- ============================================================================
-- 4️⃣ Remembered per-user, per-client consent
-- ============================================================================
-- 🚧 TABLE ONLY in Stage A1 — populated from Stage A2's consent screen.
CREATE TABLE IF NOT EXISTS tblOAuthConsents (
    consentID   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    userID      BIGINT UNSIGNED NOT NULL,
    clientID    BIGINT UNSIGNED NOT NULL,
    scope       VARCHAR(512) NOT NULL COMMENT 'Space-delimited scopes the user consented to',
    grantedAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revokedAt   DATETIME NULL COMMENT 'Set when the user revokes this app from the "Connected Apps" hub (Stage A4)',
    ipAddress   VARCHAR(45) NULL,

    FOREIGN KEY (userID)   REFERENCES tblUsers(userID) ON DELETE CASCADE,
    FOREIGN KEY (clientID) REFERENCES tblOAuthClients(clientID) ON DELETE CASCADE,
    UNIQUE KEY uk_user_client (userID, clientID),
    INDEX idx_client (clientID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-user, per-RP consent records (auditable; user-revocable) — G-001 (populated from Stage A2)';

-- ============================================================================
-- 5️⃣ Issued access grants (RP token ledger / revocation surface)
-- ============================================================================
-- 🚧 TABLE ONLY in Stage A1 — populated from Stage A3's /oauth/token. The
--    ledger a user's "Connected Apps" hub (Stage A4) reads to show + revoke
--    active third-party access; revocation denylists `accessJti` via G-003's
--    tblRevokedTokens and revokes the linked refresh-token family.
CREATE TABLE IF NOT EXISTS tblOAuthAccessGrants (
    grantID         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    userID          BIGINT UNSIGNED NOT NULL,
    clientID        BIGINT UNSIGNED NOT NULL,
    scope           VARCHAR(512) NOT NULL COMMENT 'Space-delimited scopes granted to the issued token(s)',
    accessJti       CHAR(32) NULL COMMENT 'jti of the issued access token — denylist key for revocation via G-003 tblRevokedTokens',
    refreshTokenID  BIGINT UNSIGNED NULL COMMENT 'FK into G-003 tblRefreshTokens for offline_access (NULL if no refresh token issued)',
    issuedAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiresAt       DATETIME NOT NULL COMMENT 'Original access-token exp',
    revokedAt       DATETIME NULL COMMENT 'Set on consent revocation / explicit /oauth/revoke',

    FOREIGN KEY (userID)         REFERENCES tblUsers(userID) ON DELETE CASCADE,
    FOREIGN KEY (clientID)       REFERENCES tblOAuthClients(clientID) ON DELETE CASCADE,
    FOREIGN KEY (refreshTokenID) REFERENCES tblRefreshTokens(tokenID) ON DELETE SET NULL,
    INDEX idx_user_client (userID, clientID),
    INDEX idx_jti (accessJti)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ledger of access grants issued to RPs (consent-hub display + revocation) — G-001 (populated from Stage A3)';

-- ============================================================================
-- 6️⃣ Settings: OIDC provider policy (non-sensitive)
-- ============================================================================
-- 🔑 oauth.pairwise_salt is DELIBERATELY NOT SEEDED HERE. Exactly like G-003's
--    jwt.signing_key.<kid>.private_pem (migration 030), a secret that must be
--    high-entropy and never hardcoded in a checked-in migration is instead
--    minted at RUNTIME, on first use, by application code
--    (OAuthClientManager::getPairwiseSalt(), an INSERT IGNORE + re-read pattern
--    so a concurrent first-request race cannot leave two different salts in
--    play) and stored isSensitive=1 (AES-256-CBC encrypted via
--    SecurityUtils::encrypt(), decrypted on load by config.php's
--    loadSettings() — same as every other isSensitive row).
--
-- Column list uses the REAL tblSettings columns (settingCategory, not
-- "category"; description, not "settingDescription" — see G-003 migration
-- 030's header note about this schema's inconsistent column names across
-- older migrations):
--   (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
INSERT IGNORE INTO tblSettings
    (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
VALUES
    ('oidc.enabled',           '0',                   'boolean', 0, 'oidc', 'Master switch for SIGNula-as-IdP (default OFF until Stage A2+ ships and an operator opts in)'),
    ('oidc.issuer',            'https://signula.id',  'string',  0, 'oidc', 'OIDC issuer identifier (iss claim + discovery "issuer") — MUST equal jwt.issuer'),
    ('oidc.authcode_ttl',      '60',                  'integer', 0, 'oidc', 'Authorization-code lifetime in seconds (Stage A2)'),
    ('oidc.require_pkce',      '1',                   'boolean', 0, 'oidc', 'Require PKCE (S256) for all clients, not just public ones (Stage A2)'),
    ('oidc.allow_plain_pkce',  '0',                   'boolean', 0, 'oidc', 'Allow the PKCE "plain" method — keep OFF in production (Stage A2)'),
    ('oidc.consent_remember',  '1',                   'boolean', 0, 'oidc', 'Remember a user''s consent per client+scope so repeat sign-ins skip the screen (Stage A2)'),
    ('oidc.subject_type',      'public',              'string',  0, 'oidc', 'Global advisory default for NEW client registrations: public | pairwise (per-client subjectType column can override; see migration header note)'),
    ('oidc.access_ttl',        '900',                 'integer', 0, 'oidc', 'RP access-token lifetime in seconds (Stage A3) — mirrors jwt.access_ttl default'),
    ('oidc.id_token_ttl',      '3600',                'integer', 0, 'oidc', 'id_token lifetime in seconds (Stage A3)'),
    ('oidc.refresh_enabled',   '1',                   'boolean', 0, 'oidc', 'Whether the offline_access scope may mint a refresh token (Stage A3)');
-- oauth.pairwise_salt (isSensitive=1, encrypted) — created at runtime by
-- OAuthClientManager::getPairwiseSalt() on first pairwise-subject computation.

-- ============================================================================
-- 7️⃣ Verification queries
-- ============================================================================
SELECT 'OAuth provider (G-001 A1) tables created' AS Status,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblOAuthClients')            AS tblOAuthClients_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblOAuthClientRedirectUris') AS tblOAuthClientRedirectUris_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblOAuthAuthCodes')           AS tblOAuthAuthCodes_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblOAuthConsents')            AS tblOAuthConsents_exists,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'tblOAuthAccessGrants')        AS tblOAuthAccessGrants_exists;

SELECT 'OIDC provider policy settings seeded' AS Status,
    COUNT(*) AS oidc_settings_count
FROM tblSettings WHERE settingKey LIKE 'oidc.%';

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 031_oauth_provider_clients.sql completed successfully' AS Result;
