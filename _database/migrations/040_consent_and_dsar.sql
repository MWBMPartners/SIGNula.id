-- ============================================================================
-- SIGNula Database Migration 040
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: Consent Records + Data-Subject-Request Tracker (FG-002a / G-004 L1a)
-- 📅 Date:      2026-07-10
-- 🔖 Version:   2.7.0-beta
-- 📝 Description:
--   Foundational compliance plumbing for G-004 (multi-jurisdiction data-
--   protection tooling), Layer 1 / Cycle A. Creates all three Layer-1 tables
--   as one cohesive schema (later cycles add the manager classes that use
--   the DSAR tables):
--     - tblConsentRecords           : append-only consent audit trail (users + visitors)
--     - tblDataSubjectRequests      : DSAR request tracker (delegates erasure/export to AccountManager)
--     - tblDataSubjectRequestEvents : per-request status/audit timeline
--   Plus 6 tblSettings rows (privacy category) for DSAR SLA defaults and
--   consent tamper-evidence configuration.
--
--   Extends, does NOT duplicate: tblDataExportRequests (migration 021),
--   AccountManager (web/private_html/auth/AccountManager.php), tblActivityLog.
--
--   Erasure safety (see .dev-team/specs/G-004.md §2.4): neither new table
--   uses ON DELETE CASCADE to tblUsers — a regulator may ask "prove this
--   user consented / prove you honoured their erasure" AFTER the account is
--   gone, so the fact of consent/fulfilment must survive account deletion.
--   The actual anonymisation edit to AccountManager::permanentlyDeleteAccount()
--   is a Layer-1-Cycle-B (DSAR cycle) change, not part of this migration.
--
--   regimeCode / policyVersion columns are intentionally nullable soft
--   columns in this cycle — the regime configuration model (tblComplianceRegimes)
--   is Layer 3 (a later cycle). Until then, application code leaves
--   regimeCode NULL and falls back to the dsar.default_sla_days setting.
--
-- 🔗 Related: .dev-team/specs/G-004.md §2.1 (Layer 1 data model), GitHub Issue FG-002a
-- ============================================================================

-- ============================================================================
-- 🏗️ CREATE CONSENT RECORDS TABLE
-- ============================================================================
-- 🧾 APPEND-ONLY consent audit trail. One row per consent decision (grant OR withdraw).
-- Never UPDATE a row to flip a decision — INSERT a new superseding row (immutable history).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblConsentRecords` (
    `consentID`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID`        BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'tblUsers.userID — NULL for pre-auth/anonymous visitors',
    `visitorID`     CHAR(36) NULL DEFAULT NULL
        COMMENT 'Anonymous UUID v4 (cookie-backed) for pre-login consent; reconciled to userID on signup',
    `consentType`   VARCHAR(64) NOT NULL
        COMMENT 'Free-but-conventional key: cookies.necessary, cookies.analytics, cookies.marketing, cookies.functional, marketing.email, terms, privacy_policy, data_processing, do_not_sell, parental — value set is data-driven, see tblConsentCategories (L2)',
    `granted`       TINYINT(1) NOT NULL
        COMMENT '1=granted/opt-in, 0=withdrawn/opt-out',
    `policyVersion` VARCHAR(32) NULL DEFAULT NULL
        COMMENT 'Version string of the policy/banner the decision was made against (see tblPolicyVersions, L2)',
    `mechanism`     ENUM('banner','preference_center','signup','api','gpc_signal','admin','import')
                    NOT NULL DEFAULT 'banner'
        COMMENT 'How the decision was captured — GPC auto-opt-outs are mechanism=gpc_signal',
    `regimeCode`    VARCHAR(16) NULL DEFAULT NULL
        COMMENT 'Resolved jurisdiction at capture time (FK-soft to tblComplianceRegimes.regimeCode, L3) — denormalised on purpose so the audit row is self-contained even if regime config changes',
    `ipAddress`     VARBINARY(16) NULL
        COMMENT 'IPv4/IPv6 stored as packed bytes via inet_pton() — minimised; never plaintext geo',
    `userAgent`     VARCHAR(512) NULL,
    `proofHash`     CHAR(64) NULL
        COMMENT 'SHA-256 over (consentType|granted|policyVersion|ts|ip|ua) — tamper-evidence for audits',
    `createdAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`consentID`),
    INDEX `idx_consent_user`    (`userID`, `consentType`, `createdAt`),
    INDEX `idx_consent_visitor` (`visitorID`, `consentType`, `createdAt`),
    INDEX `idx_consent_type`    (`consentType`, `granted`)
    -- NB: NO FK to tblUsers ON DELETE CASCADE — consent history must SURVIVE erasure as
    -- an anonymised compliance record (set userID=NULL on erasure, keep the row). See §2.4.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only consent audit trail (FG-002a)';

-- ============================================================================
-- 🏗️ CREATE DATA SUBJECT REQUESTS TABLE
-- ============================================================================
-- 📨 DSAR tracker. Superset of tblDataExportRequests; for erasure/portability it DELEGATES.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblDataSubjectRequests` (
    `requestID`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userID`        BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'NULL allowed: a request can arrive by email before identity is matched',
    `requestType`   ENUM('access','portability','erasure','rectification','restriction',
                         'object','do_not_sell','withdraw_consent','automated_decision_optout')
                    NOT NULL
        COMMENT 'Union of rights across regimes; which apply per user is resolved from L3 config',
    `status`        ENUM('submitted','identity_pending','verified','in_progress',
                         'awaiting_user','fulfilled','rejected','partially_fulfilled','expired','withdrawn')
                    NOT NULL DEFAULT 'submitted',
    `regimeCode`    VARCHAR(16) NULL
        COMMENT 'Governing regime → drives dueDate SLA (resolved from tblComplianceRegimes, L3)',
    `requesterEmail` VARCHAR(255) NULL
        COMMENT 'For unauthenticated/email-channel requests; verified before fulfilment',
    `subjectRef`    VARCHAR(255) NULL
        COMMENT 'On-behalf-of reference (e.g. parent for a child, agent for data subject)',
    `details`       TEXT NULL COMMENT 'Free-text request detail (sanitised on input)',
    `rectifyPayload` JSON NULL COMMENT 'For rectification: proposed field→value changes (admin-reviewed)',
    `linkedExportID` BIGINT UNSIGNED NULL
        COMMENT 'FK to tblDataExportRequests.exportID when fulfilled via AccountManager::exportUserData()',
    `verificationToken` CHAR(64) NULL COMMENT 'SHA-256 of identity-verification token (never store raw)',
    `verifiedAt`    DATETIME NULL,
    `dueDate`       DATE NULL COMMENT 'Computed = submittedAt + regime SLA days (L3); admin-visible countdown',
    `assignedAdminID` BIGINT UNSIGNED NULL,
    `resolutionNote` TEXT NULL,
    `ipAddress`     VARBINARY(16) NULL,
    `submittedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fulfilledAt`   DATETIME NULL,
    PRIMARY KEY (`requestID`),
    INDEX `idx_dsar_status_due` (`status`, `dueDate`),
    INDEX `idx_dsar_user`       (`userID`, `requestType`),
    INDEX `idx_dsar_export`     (`linkedExportID`)
    -- Soft link to tblDataExportRequests (no hard FK so erasure of the export row can't orphan a DSAR).
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Data-subject-request tracker (FG-002a) — wraps AccountManager for erasure/portability';

-- ============================================================================
-- 🏗️ CREATE DATA SUBJECT REQUEST EVENTS TABLE
-- ============================================================================
-- 🕒 Per-request audit timeline (who did what when) — feeds the admin queue + compliance reports.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblDataSubjectRequestEvents` (
    `eventID`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `requestID`   BIGINT UNSIGNED NOT NULL,
    `fromStatus`  VARCHAR(32) NULL,
    `toStatus`    VARCHAR(32) NOT NULL,
    `actorType`   ENUM('user','admin','system','cron') NOT NULL DEFAULT 'system',
    `actorID`     BIGINT UNSIGNED NULL,
    `note`        TEXT NULL,
    `createdAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`eventID`),
    CONSTRAINT `fk_dsar_event_request` FOREIGN KEY (`requestID`)
        REFERENCES `tblDataSubjectRequests` (`requestID`) ON DELETE CASCADE,
    INDEX `idx_dsar_event_request` (`requestID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='DSAR status-change audit timeline';

-- ============================================================================
-- 🏷️ WIDEN tblActivityLog.activityCategory ENUM (add 'privacy')
-- ============================================================================
-- Mandatory correction (verified against the live schema, same class of fix
-- as migration 029_activity_category_enum_widen.sql): ConsentManager::record()
-- calls `ActivityLogger::log($userID, 'consent_grant'|'consent_withdraw',
-- 'privacy', 'info', ...)` for every consent decision (see
-- .dev-team/specs/G-004.md §2.2) — but 'privacy' was NOT a member of
-- tblActivityLog.activityCategory's ENUM. Under strict SQL mode that INSERT
-- fails with "Data truncated for column 'activityCategory'"; ActivityLogger::log()
-- fails safe (catches the exception, error_log()s it, returns false), so
-- consent decisions would otherwise record correctly in tblConsentRecords but
-- SILENTLY never reach the audit log — the exact failure mode migration 029
-- already fixed once for 'authentication'/'organization'/'billing'/'email'/
-- 'webhooks'/'api_keys'. 'privacy' joins that list for the same reason.
--
-- Safety: ADDITIVE / NON-DESTRUCTIVE, mirrors 029 exactly — every existing
-- ENUM member is retained in its original position, DEFAULT is unchanged,
-- and re-running this ALTER simply re-asserts the same widened definition.
-- ============================================================================

ALTER TABLE `tblActivityLog`
    MODIFY `activityCategory`
        ENUM(
            -- ── original members (unchanged, order preserved) ──
            'auth',
            'account',
            'security',
            'payment',
            'api',
            'admin',
            'system',
            'other',
            -- ── added by migration 029 ──
            'authentication',
            'organization',
            'billing',
            'email',
            'webhooks',
            'api_keys',
            -- ── added by migration 040 (G-004 L1a — consent audit trail) ──
            'privacy'
        )
        NOT NULL DEFAULT 'other'
        COMMENT 'Activity category';

-- ============================================================================
-- ⚙️ SETTINGS
-- ============================================================================
-- Data-driven SLA defaults; per-regime override comes in L3. Real tblSettings
-- columns (verified against information_schema — the spec draft used a
-- non-existent `settingDescription` column, corrected here) are:
--   settingKey, settingValue, settingType, settingCategory, isSensitive, description
-- `settingCategory` is NOT NULL, so every row below sets it to 'privacy'.
-- `ON DUPLICATE KEY UPDATE settingKey=settingKey` is a no-op update that
-- makes this INSERT idempotent against settingKey's UNIQUE constraint,
-- matching the house "additive + re-runnable" migration rule.
-- ============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('dsar.default_sla_days', '30', 'integer', 'privacy', 0,
     'Fallback DSAR fulfilment SLA (in days) when no regime is mapped (most-protective default)')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('dsar.identity_verification.required', 'true', 'boolean', 'privacy', 0,
     'Require identity verification before fulfilling a DSAR')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('dsar.identity_token.ttl_minutes', '30', 'integer', 'privacy', 0,
     'Lifetime (in minutes) of the DSAR identity-verification email token')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('dsar.admin.notify_on_new', 'true', 'boolean', 'privacy', 0,
     'Notify compliance admins when a new DSAR arrives')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('dsar.due_soon.warn_days', '7', 'integer', 'privacy', 0,
     'Warn admins when a DSAR is this many days from its due date')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('consent.proof_hash.enabled', 'true', 'boolean', 'privacy', 0,
     'Compute a tamper-evidence proofHash (SHA-256) on each consent record')
    ON DUPLICATE KEY UPDATE settingKey = settingKey;

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
