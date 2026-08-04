-- ============================================================================
-- SIGNula Database Migration 049
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- 🚀 Migration: Generic OpenID Connect (OIDC) provider — issue #99 / FG-008,
--               the "OpenID" bullet in the project brief's third-party
--               account-linkage list.
-- 📅 Date:      2026-08-04
-- 🔖 Version:   2.9.0-beta
-- 📝 Description:
--   web/private_html/auth/providers/GenericOidcProvider.php is a
--   CONFIG-DRIVEN OIDC connector: given only an `issuer` URL, it performs
--   OIDC Discovery (RFC-equivalent spec:
--   https://openid.net/specs/openid-connect-discovery-1_0.html) to resolve
--   the authorization/token/userinfo/JWKS endpoints at runtime, so ANY
--   standards-compliant identity provider (Okta, Keycloak, Auth0, Azure AD
--   B2C, a corporate SSO tenant, ...) can be linked purely via settings — no
--   new PHP class per IdP. See that class's file-level docblock for the full
--   design (PKCE, nonce, id_token/JWKS verification, multiple named
--   instances via `oauth.{key}.*` settings).
--
--   This migration seeds settings for exactly ONE thing:
--
--   1️⃣ A DISABLED `oauth.lastpass.*` template (issue #99 explicitly asks
--      for this — LastPass has NO consumer OAuth/OIDC login API; its SSO
--      offering is SAML 2.0, for Business/Enterprise accounts only, with
--      LastPass itself acting as the SAML Identity Provider for OTHER apps:
--      https://support.lastpass.com/help/lastpass-admin-toolkit-using-single-sign-on-sso
--      There is deliberately NO bespoke "LastpassOAuth.php" class — nothing
--      public exists to hardcode. This blank/disabled template is a
--      documented starting point ONLY: if an organisation ever fronts
--      LastPass Enterprise SSO behind an OIDC-compatible bridge (or
--      SIGNula's own SAML Identity Provider — issue #100 — is used instead),
--      an admin fills in `oauth.lastpass.issuer` / `.client_id` /
--      `.client_secret` via the admin OAuth settings UI
--      (web/public_html/admin/settings/oauth.php) and
--      `new GenericOidcProvider('lastpass')` starts working immediately —
--      see web/public_html/oauth/authorize.php's / callback.php's
--      provider-class-resolution fallback, which is what wires an
--      unrecognised provider key to GenericOidcProvider automatically.
--
--   The single generic 'oidc' slot itself is DELIBERATELY NOT seeded here —
--   mirroring the established precedent for oauth.yahoo.* / oauth.amazon.* /
--   oauth.paypal.* / oauth.wordpress.* (none of which have a seeding
--   migration either): the admin settings UI's "Save Configuration" action
--   (web/public_html/admin/api/settings-actions.php, action=create_setting)
--   creates each `oauth.oidc.*` row on demand the first time an admin
--   actually configures it. Until then, getSetting('oauth.oidc.issuer', '')
--   simply falls back to '' (no row needed) and isConfigured() correctly
--   reports "not usable" — identical additive/opt-in behaviour to every
--   other provider on this page.
--
--   `redirect_uri` and `scopes` are DELIBERATELY NOT seeded (not even as
--   blank rows) for the same reason the baked-in installer never seeded them
--   for google/microsoft/apple/facebook/linkedin/github either: PHP's `??`
--   null-coalescing operator only falls back to a DEFAULT when a setting key
--   is completely ABSENT, not when it exists with an empty string value. If
--   we seeded `oauth.lastpass.redirect_uri = ''` here, that row's mere
--   EXISTENCE would permanently override
--   OAuth::loadConfiguration()'s dynamic default
--   (`getSetting('app.url', ...) . '/oauth/callback'`) with a blank string —
--   breaking that fallback the moment an admin fills in the OTHER fields.
--   Leaving the key absent entirely preserves the intended dynamic default.
--
--   🛡️ Idempotent + additive + non-destructive:
--     - Every row below is INSERT ... ON DUPLICATE KEY UPDATE against the
--       settingKey UNIQUE constraint, but the UPDATE clause is the
--       self-referencing `settingKey = settingKey` no-op idiom already used
--       by 040/046/047 — replaying this migration is a silent no-op AND
--       never clobbers a value an admin may have since edited.
--     - No new tables/columns. No `USE <db>;`. No stored-procedure DELIMITER
--       switching (multi_query-safe — mirrors 030/.../048's header notes).
--     - Nothing here changes existing behaviour: with `oauth.lastpass.*`
--       seeded blank/disabled, login.php's `getSetting("oauth.{$key}.client_id")`
--       filter and connected-accounts.php's `getSetting("oauth.{$key}.enabled")`
--       check both correctly keep every new button/card hidden until an
--       admin deliberately fills them in.
--
-- @see web/private_html/auth/providers/GenericOidcProvider.php (the connector this migration supports)
-- @see web/public_html/admin/settings/oauth.php (admin configuration UI — 'oidc' + 'lastpass' cards)
-- @see web/public_html/login.php (login button registry — 'oidc' + 'lastpass' entries)
-- @see web/public_html/settings/connected-accounts.php (account-linking registry — 'oidc' + 'lastpass' entries)
-- @see web/public_html/oauth/authorize.php / callback.php (provider-class-resolution fallback to GenericOidcProvider)
-- @see https://openid.net/specs/openid-connect-discovery-1_0.html (OIDC Discovery 1.0)
-- @see https://support.lastpass.com/help/lastpass-admin-toolkit-using-single-sign-on-sso (LastPass SSO is SAML-only)
-- ============================================================================

-- (No `USE <db>;` — see header note above; mirrors 030/.../048.)


-- ============================================================================
-- 1️⃣ LastPass generic-OIDC template — DISABLED, blank, documented starting point
-- ============================================================================
-- `client_id`/`client_secret` use settingType 'encrypted' + isSensitive=1,
-- exactly matching the convention the baked-in installer already used for
-- oauth.google.client_id / oauth.microsoft.client_id / etc. (even though a
-- client_id is not itself a secret, this mirrors that existing convention
-- for consistency rather than introducing a one-off exception here).
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    (
        'oauth.lastpass.enabled',
        '0',
        'boolean',
        'oauth',
        0,
        1,
        'FG-008 (issue #99) DISABLED-by-default template. LastPass has no consumer OAuth/OIDC login API (SAML-only, enterprise) — see https://support.lastpass.com/help/lastpass-admin-toolkit-using-single-sign-on-sso. Leave this OFF unless your organisation fronts LastPass Enterprise SSO with an OIDC-compatible bridge; the generic connector (web/private_html/auth/providers/GenericOidcProvider.php) then works via oauth.lastpass.issuer/client_id/client_secret below. See also SIGNula''s own SAML Identity Provider (issue #100) as an alternative.',
        '0'
    ),
    (
        'oauth.lastpass.issuer',
        '',
        'string',
        'oauth',
        0,
        1,
        'FG-008 template (blank by default). The OIDC issuer URL for a LastPass-Enterprise-fronting OIDC bridge, if one exists for your organisation. GenericOidcProvider performs OIDC Discovery against {issuer}/.well-known/openid-configuration.',
        ''
    ),
    (
        'oauth.lastpass.client_id',
        '',
        'encrypted',
        'oauth',
        1,
        1,
        'FG-008 template (blank by default). OIDC client_id for the LastPass Enterprise SSO bridge described in oauth.lastpass.issuer''s description. Encrypted at rest.',
        ''
    ),
    (
        'oauth.lastpass.client_secret',
        '',
        'encrypted',
        'oauth',
        1,
        1,
        'FG-008 template (blank by default). OIDC client_secret for the LastPass Enterprise SSO bridge described in oauth.lastpass.issuer''s description. Encrypted at rest.',
        ''
    )
    ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- ============================================================================
-- ✅ VERIFICATION QUERIES
-- ============================================================================
SELECT 'oauth.lastpass.* seeded rows (expect 4, all blank/disabled)' AS Status,
    settingKey, settingValue, settingType, isSensitive, isEditable
FROM `tblSettings`
WHERE settingKey IN (
    'oauth.lastpass.enabled',
    'oauth.lastpass.issuer',
    'oauth.lastpass.client_id',
    'oauth.lastpass.client_secret'
)
ORDER BY settingKey;

SELECT 'oauth.oidc.* rows (expect ZERO — created on-demand by the admin UI, see header note)' AS Status,
    COUNT(*) AS existingRows
FROM `tblSettings`
WHERE settingKey LIKE 'oauth.oidc.%';


-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT '✅ Migration 049_generic_oidc_provider.sql completed successfully — GenericOidcProvider connector active; oauth.lastpass.* seeded as a DISABLED documented template; oauth.oidc.* created on-demand via the admin OAuth settings UI when first configured' AS Result;
