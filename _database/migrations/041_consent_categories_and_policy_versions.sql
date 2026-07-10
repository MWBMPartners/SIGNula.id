-- ============================================================================
-- SIGNula Database Migration 041
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: Consent-Management Surface — Categories + Policy Versions (G-004 Layer 2)
-- 📅 Date:      2026-07-10
-- 🔖 Version:   2.7.0-beta
-- 📝 Description:
--   Layer 2 of G-004 (multi-jurisdiction data-protection tooling): the
--   consent-management SURFACE that sits on top of Layer 1's
--   tblConsentRecords (migration 040). Creates:
--     - tblConsentCategories : data-driven granular consent categories the
--                              server-side banner/preference-center renders
--                              (necessary/functional/analytics/marketing).
--                              consentType for a category is recorded as
--                              'cookies.<categoryKey>' via ConsentManager.
--     - tblPolicyVersions    : version ANCHORS for terms/privacy/cookies —
--                              NO legal text, just a version string + optional
--                              effectiveDate/contentHash/changelog, so
--                              re-consent-on-version-change is possible.
--   Plus 7 tblSettings rows (privacy category) controlling the banner,
--   re-consent enforcement, GPC honoring, the visitor cookie, and the
--   Do-Not-Sell toggle.
--
--   ⚠️ NO LEGAL CONTENT SHIPS IN THIS MIGRATION. tblConsentCategories labels/
--   descriptions are plain functional UI copy ("Strictly Necessary",
--   "Functional", ...), NOT legal disclosures. tblPolicyVersions seeds ONE
--   DRAFT anchor row per policyKey (version='1.0', effectiveDate=NULL,
--   changelog explicitly flagged as a placeholder) purely so the re-consent
--   machinery has a version to compare against — owner/counsel must set the
--   real effective policy version and legal text separately.
--
--   consent.reconsent.enforce ships '0' (OFF) deliberately — flipping the
--   web/public_html/login.php re-consent redirect on is an explicit,
--   config-gated decision, not a side-effect of installing this migration.
--
-- 🔗 Related: .dev-team/specs/G-004.md §3 (Layer 2 — consent-management
--    surface), _database/migrations/040_consent_and_dsar.sql (Layer 1,
--    tblConsentRecords / ConsentManager this layer builds on top of).
-- ============================================================================

-- ============================================================================
-- 🏗️ CREATE CONSENT CATEGORIES TABLE
-- ============================================================================
-- 🍪 Data-driven granular consent categories. The banner/preference center
-- renders EVERY active row from this table — adding a 5th category is a row
-- insert, never a PHP code change. 'necessary' (isStrictlyNecessary=1) is
-- rendered checked+disabled client-side AND enforced server-side (see
-- ConsentCategoryService::recordBannerChoices() — it forces granted=1 for
-- any category with isStrictlyNecessary=1 regardless of caller input).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblConsentCategories` (
    `categoryID`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `categoryKey`         VARCHAR(64) NOT NULL
        COMMENT 'Stable key, e.g. necessary/functional/analytics/marketing — recorded as consentType "cookies.<categoryKey>" via ConsentManager',
    `label`               VARCHAR(128) NOT NULL
        COMMENT 'Short display label shown in the banner/preference center — functional UI copy, NOT legal text',
    `description`         TEXT NULL
        COMMENT 'Short explanatory copy shown under the label — functional UI copy, NOT legal text',
    `isStrictlyNecessary` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = cannot be toggled off by the subject; enforced SERVER-SIDE in ConsentCategoryService::recordBannerChoices(), not just a disabled UI checkbox',
    `defaultGranted`      TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Pre-checked state offered in the banner UI before the subject makes an explicit choice',
    `displayOrder`        INT NOT NULL DEFAULT 0
        COMMENT 'Ascending render order in the banner/preference center',
    `isActive`            TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Inactive categories are neither rendered nor recorded',
    `createdAt`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`categoryID`),
    UNIQUE KEY `uq_consent_category_key` (`categoryKey`),
    INDEX `idx_consent_category_active_order` (`isActive`, `displayOrder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Data-driven granular consent categories (G-004 Layer 2)';

-- 🌱 Seed the four Layer-2 categories. Idempotent: `ON DUPLICATE KEY UPDATE
-- categoryKey = categoryKey` is a no-op update against the UNIQUE(categoryKey)
-- constraint, matching migration 040's house "additive + re-runnable" pattern
-- (never clobbers an admin's later edits to label/description/displayOrder).

INSERT INTO `tblConsentCategories`
    (`categoryKey`, `label`, `description`, `isStrictlyNecessary`, `defaultGranted`, `displayOrder`, `isActive`)
VALUES
    ('necessary', 'Strictly Necessary',
     'Required for the site and account sign-in to function. These cannot be turned off.',
     1, 1, 10, 1)
    ON DUPLICATE KEY UPDATE categoryKey = categoryKey;

INSERT INTO `tblConsentCategories`
    (`categoryKey`, `label`, `description`, `isStrictlyNecessary`, `defaultGranted`, `displayOrder`, `isActive`)
VALUES
    ('functional', 'Functional',
     'Remembers choices you make (such as language or region) to provide a more personalised experience.',
     0, 0, 20, 1)
    ON DUPLICATE KEY UPDATE categoryKey = categoryKey;

INSERT INTO `tblConsentCategories`
    (`categoryKey`, `label`, `description`, `isStrictlyNecessary`, `defaultGranted`, `displayOrder`, `isActive`)
VALUES
    ('analytics', 'Analytics',
     'Helps us understand how the site is used so we can improve it.',
     0, 0, 30, 1)
    ON DUPLICATE KEY UPDATE categoryKey = categoryKey;

INSERT INTO `tblConsentCategories`
    (`categoryKey`, `label`, `description`, `isStrictlyNecessary`, `defaultGranted`, `displayOrder`, `isActive`)
VALUES
    ('marketing', 'Marketing',
     'Used to tailor marketing messages and measure campaign effectiveness.',
     0, 0, 40, 1)
    ON DUPLICATE KEY UPDATE categoryKey = categoryKey;

-- ============================================================================
-- 🏗️ CREATE POLICY VERSIONS TABLE
-- ============================================================================
-- 📜 Version ANCHORS only — never legal text. `isCurrent=1` marks the row
-- ConsentCategoryService::getCurrentPolicyVersion()/the re-consent check
-- compare against. Only one row per policyKey is expected to carry
-- isCurrent=1 at a time (enforced by application logic when a new version is
-- published, not by a DB constraint — publishing a new version is an admin
-- workflow outside this cycle's scope).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblPolicyVersions` (
    `policyVersionID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `policyKey`       ENUM('terms','privacy','cookies') NOT NULL
        COMMENT 'Which legal document this version anchors',
    `version`         VARCHAR(32) NOT NULL
        COMMENT 'Version string, e.g. "1.0", "2026-07-10" — compared verbatim as a string, no semver parsing',
    `effectiveDate`   DATE NULL DEFAULT NULL
        COMMENT 'NULL until owner/counsel sets a real effective date',
    `contentHash`     CHAR(64) NULL DEFAULT NULL
        COMMENT 'Optional SHA-256 of the published document text, for change-detection',
    `isCurrent`       TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = the active version new consent is captured against',
    `changelog`       TEXT NULL DEFAULT NULL
        COMMENT 'What changed vs the previous version — free text',
    `createdAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`policyVersionID`),
    UNIQUE KEY `uq_policy_key_version` (`policyKey`, `version`),
    INDEX `idx_policy_key_current` (`policyKey`, `isCurrent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Version anchors for terms/privacy/cookies — NO legal text (G-004 Layer 2)';

-- 🌱 Seed ONE draft anchor row per policyKey. Idempotent via
-- ON DUPLICATE KEY UPDATE against UNIQUE(policyKey, version). NO legal
-- content — changelog explicitly flags these as placeholders pending
-- owner/legal confirmation of the real effective version/date.

INSERT INTO `tblPolicyVersions` (`policyKey`, `version`, `effectiveDate`, `isCurrent`, `changelog`)
VALUES ('terms', '1.0', NULL, 1, 'DRAFT — owner/counsel to set the effective policy version')
    ON DUPLICATE KEY UPDATE policyKey = policyKey;

INSERT INTO `tblPolicyVersions` (`policyKey`, `version`, `effectiveDate`, `isCurrent`, `changelog`)
VALUES ('privacy', '1.0', NULL, 1, 'DRAFT — owner/counsel to set the effective policy version')
    ON DUPLICATE KEY UPDATE policyKey = policyKey;

INSERT INTO `tblPolicyVersions` (`policyKey`, `version`, `effectiveDate`, `isCurrent`, `changelog`)
VALUES ('cookies', '1.0', NULL, 1, 'DRAFT — owner/counsel to set the effective policy version')
    ON DUPLICATE KEY UPDATE policyKey = policyKey;

-- ============================================================================
-- ⚙️ SETTINGS (G-004 Layer 2)
-- ============================================================================
-- Real tblSettings columns (verified against migration 040's own note):
--   settingKey, settingValue, settingType, settingCategory, isSensitive, description
-- Every row is `settingCategory = 'privacy'`, idempotent via
-- ON DUPLICATE KEY UPDATE settingKey = settingKey.
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES ('consent.banner.enabled', '1', 'boolean', 'privacy', 0,
        'Show the server-persisted cookie/consent banner on public pages')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES ('consent.banner.style', 'bar', 'string', 'privacy', 0,
        'Banner presentation style: bar|modal|corner')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES ('consent.reconsent.enforce', '0', 'boolean', 'privacy', 0,
        'When ON, a logged-in user whose terms/privacy consent policyVersion is stale is redirected to /legal/reconsent before continuing. Ships OFF so existing login flows are unaffected until explicitly enabled.')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES ('consent.gpc.honor', '1', 'boolean', 'privacy', 0,
        'Honor the Sec-GPC: 1 request header as an automatic Do-Not-Sell opt-out')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES ('consent.visitor_cookie.name', 'SIGNULA_VID', 'string', 'privacy', 0,
        'Cookie name for the anonymous pre-login visitorID (UUID v4)')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES ('consent.visitor_cookie.ttl_days', '365', 'integer', 'privacy', 0,
        'Lifetime (in days) of the visitor consent-tracking cookie')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES ('consent.do_not_sell.enabled', '1', 'boolean', 'privacy', 0,
        'Show the "Do Not Sell or Share My Personal Information" toggle')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
