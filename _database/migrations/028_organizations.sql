-- ============================================================================
-- 🏢 SIGNula - Migration 028: Multi-Organization Support Tables
-- ============================================================================
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration: 028_organizations.sql
-- Version:   2.7.0-beta
-- Date:      2026-07-01
-- Purpose:   Create the organization / enterprise-account tables that
--            web/private_html/auth/Organization.php (and the /organization/*
--            pages + Auth.php auto-enrolment) reference at runtime but which NO
--            prior migration ever created. Without these tables every org flow
--            (create / findByEmailDomain / verifyDomain / inviteMember /
--            userHasRole / getMembers / isOAuthProviderAllowed) fatals with
--            "Table 'tblOrganizations' doesn't exist".
--
-- Tables created (all additive, CREATE TABLE IF NOT EXISTS — safe to re-run):
--   • tblOrganizations              — organization/company accounts
--   • tblOrganizationDomains        — verified email domains (auto-enrolment)
--   • tblOrganizationMembers        — user↔org membership + role
--   • tblOrganizationSettings       — per-org key/value policy store
--   • tblOrganizationOAuthProviders — allowed OAuth providers/tenants per org
--   • tblOrganizationInvitations    — pending member invitations
--
-- 🧩 B-034 (cycle 11) derivation notes — the column set is the UNION of every
--    SQL statement that touches these tables, so it satisfies ALL usages:
--      - Organization.php: organizationUUID, organizationName, organizationSlug,
--        organizationType, website, contactEmail, createdBy, accountStatus,
--        subscriptionTier, deletedAt, isVerified, verifiedAt   (tblOrganizations)
--        organizationID, domain, isVerified, verificationMethod, verifiedAt,
--        verifiedBy                                            (tblOrganizationDomains)
--        memberID, organizationID, userID, role, status, joinedAt (tblOrganizationMembers)
--        organizationID, email, role, invitationToken, invitedBy, message,
--        expiresAt                                             (tblOrganizationInvitations)
--        organizationID, provider, isEnabled, tenantID         (tblOrganizationOAuthProviders)
--      - web/public_html/organization/*.php + Auth.php add: domainID, tenantID,
--        autoEnroll, createdAt (domains); invitationID, status, token, updatedAt,
--        acceptedBy, acceptedAt (invitations).
--
--    ⚠️ Consumer schema conflict on tblOrganizationInvitations: Organization.php
--       writes `invitationToken`, but organization/members.php's "resend" path
--       writes `token`. To satisfy BOTH without touching app code, this table
--       carries BOTH columns: `invitationToken` (canonical, UNIQUE, NOT NULL) and
--       a nullable secondary `token` the resend path updates.
--
-- 🔑 House schema conventions (cycle-9 canonical): BIGINT UNSIGNED identity
--    columns and FKs (matching tblUsers.userID width), InnoDB,
--    utf8mb4_unicode_ci, standard file header, and — importantly — NO self-write
--    to tblMigrations (the installer/wizard runner and setup_test_db.sh track
--    application; a migration must not bookkeep itself).
--
-- 🔒 multi_query-safe: contains NO `DELIMITER` / stored procedures / events, so
--    it applies cleanly through the install wizard's mysqli::multi_query() path
--    as well as the mysql CLI. (The archived draft _database/archive/
--    002_organizations_migration.sql used DELIMITER-based procedures/events and
--    settingGroup/displayOrder columns that no longer exist in the canonical
--    schema; those parts are intentionally NOT reproduced here.)
--
-- Dependencies:
--   • tblUsers (core install)                — FK targets for members/invitations
--   • tblUsers.organizationID (migration 025)— FK back-reference added below (guarded)
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 🏢 TABLE: tblOrganizations
-- Purpose: Organization / company accounts
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizations` (
    `organizationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique organization identifier',
    `organizationUUID` CHAR(36) NOT NULL UNIQUE COMMENT 'Universal unique identifier (enumeration-safe external ref)',
    `organizationName` VARCHAR(255) NOT NULL COMMENT 'Organization display name',
    `organizationSlug` VARCHAR(100) NOT NULL UNIQUE COMMENT 'URL-friendly slug',
    `organizationType` ENUM('company', 'nonprofit', 'education', 'government', 'other') NOT NULL DEFAULT 'company' COMMENT 'Organization type',
    `logo` TEXT NULL COMMENT 'Organization logo URL or base64',
    `website` VARCHAR(500) NULL COMMENT 'Organization website',
    `industry` VARCHAR(100) NULL COMMENT 'Industry / sector',
    `employeeCount` ENUM('1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5001+') NULL COMMENT 'Number of employees',
    `contactEmail` VARCHAR(255) NULL COMMENT 'Primary contact email',
    `contactPhone` VARCHAR(20) NULL COMMENT 'Primary contact phone',
    `addressLine1` VARCHAR(255) NULL COMMENT 'Address line 1',
    `addressLine2` VARCHAR(255) NULL COMMENT 'Address line 2',
    `city` VARCHAR(100) NULL COMMENT 'City',
    `state` VARCHAR(100) NULL COMMENT 'State / Province',
    `postalCode` VARCHAR(20) NULL COMMENT 'Postal / ZIP code',
    `country` VARCHAR(100) NULL COMMENT 'Country',
    `taxID` VARCHAR(50) NULL COMMENT 'Tax ID / EIN (store encrypted at app layer)',
    `billingEmail` VARCHAR(255) NULL COMMENT 'Billing contact email',
    `accountStatus` ENUM('active', 'suspended', 'pending_verification', 'deleted') NOT NULL DEFAULT 'pending_verification' COMMENT 'Organization lifecycle status',
    `subscriptionTier` ENUM('free', 'team', 'business', 'enterprise') NOT NULL DEFAULT 'free' COMMENT 'Subscription tier',
    `maxUsers` INT UNSIGNED NULL DEFAULT 10 COMMENT 'Maximum allowed users (NULL = unlimited)',
    `isVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Domain-verified flag',
    `verifiedAt` DATETIME NULL COMMENT 'Verification timestamp',
    `createdBy` BIGINT UNSIGNED NULL COMMENT 'User who created the organization',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `deletedAt` DATETIME NULL COMMENT 'Soft-delete timestamp (Organization.php filters on deletedAt IS NULL)',

    INDEX `idx_organizationSlug` (`organizationSlug`),
    INDEX `idx_accountStatus` (`accountStatus`),
    INDEX `idx_createdBy` (`createdBy`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organizations / companies';

-- ============================================================================
-- 🌐 TABLE: tblOrganizationDomains
-- Purpose: Verified email domains used for auto-enrolment
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationDomains` (
    `domainID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique domain identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'FK → tblOrganizations',
    `domain` VARCHAR(255) NOT NULL COMMENT 'Email domain (e.g. company.com)',
    `isVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Domain verification status',
    `verificationMethod` ENUM('dns', 'email', 'file', 'manual') NULL COMMENT 'How the domain was verified',
    `verificationToken` VARCHAR(128) NULL COMMENT 'Verification challenge token',
    `verifiedAt` DATETIME NULL COMMENT 'Verification timestamp',
    `verifiedBy` BIGINT UNSIGNED NULL COMMENT 'User who verified the domain',
    `isPrimary` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Primary domain for the organization',
    `autoEnroll` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Auto-enroll users registering with this domain',
    `tenantID` VARCHAR(255) NULL COMMENT 'Associated OAuth tenant ID (e.g. MS365 tenant)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Domain added timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    UNIQUE KEY `unique_domain` (`domain`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_domain` (`domain`),
    INDEX `idx_isVerified` (`isVerified`),
    CONSTRAINT `fk_orgdomains_org`
        FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization verified domains';

-- ============================================================================
-- 👥 TABLE: tblOrganizationMembers
-- Purpose: Link users to organizations with a role and status
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationMembers` (
    `memberID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique membership record identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'FK → tblOrganizations',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'FK → tblUsers',
    `role` ENUM('owner', 'admin', 'member', 'guest') NOT NULL DEFAULT 'member' COMMENT 'Role within the organization',
    `department` VARCHAR(100) NULL COMMENT 'Department / team',
    `jobTitle` VARCHAR(100) NULL COMMENT 'Job title',
    `permissions` TEXT NULL COMMENT 'JSON array of specific granted permissions',
    `invitedBy` BIGINT UNSIGNED NULL COMMENT 'User who invited this member',
    `invitedAt` DATETIME NULL COMMENT 'Invitation timestamp',
    `joinedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Membership start timestamp',
    `status` ENUM('active', 'invited', 'suspended', 'left') NOT NULL DEFAULT 'invited' COMMENT 'Membership status',
    `leftAt` DATETIME NULL COMMENT 'When the user left the organization',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    UNIQUE KEY `unique_org_user` (`organizationID`, `userID`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_role` (`role`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_orgmembers_org`
        FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_orgmembers_user`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization members';

-- ============================================================================
-- ⚙️ TABLE: tblOrganizationSettings
-- Purpose: Per-organization key/value policy store
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationSettings` (
    `settingID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique setting identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'FK → tblOrganizations',
    `settingKey` VARCHAR(255) NOT NULL COMMENT 'Setting key',
    `settingValue` TEXT NULL COMMENT 'Setting value',
    `settingType` ENUM('string', 'integer', 'float', 'boolean', 'json', 'array') NOT NULL DEFAULT 'string' COMMENT 'Value data type',
    `description` TEXT NULL COMMENT 'Human-readable description',
    `isEditable` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Editable by org admins',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `updatedBy` BIGINT UNSIGNED NULL COMMENT 'User who last updated',

    UNIQUE KEY `unique_org_setting` (`organizationID`, `settingKey`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_settingKey` (`settingKey`),
    CONSTRAINT `fk_orgsettings_org`
        FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization settings / policies';

-- ============================================================================
-- 🔗 TABLE: tblOrganizationOAuthProviders
-- Purpose: Allowed OAuth providers / tenants per organization
--          (Organization.php::isOAuthProviderAllowed)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationOAuthProviders` (
    `providerID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique provider-policy identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'FK → tblOrganizations',
    `provider` ENUM('google', 'microsoft', 'apple', 'facebook', 'instagram', 'linkedin', 'lastpass', 'yahoo', 'wordpress', 'amazon', 'paypal', 'openid', 'github', 'twitter') NOT NULL COMMENT 'OAuth provider identifier',
    `isEnabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Provider allowed for this organization',
    `tenantID` VARCHAR(255) NULL COMMENT 'Specific tenant ID (e.g. MS365 tenant); NULL = any',
    `tenantName` VARCHAR(255) NULL COMMENT 'Tenant display name',
    `allowedDomains` TEXT NULL COMMENT 'JSON array of allowed email domains for this provider',
    `requireTenant` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Require a specific tenant match',
    `autoEnroll` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Auto-enroll users authenticating via this provider',
    `metadata` TEXT NULL COMMENT 'Additional provider-specific settings (JSON)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Provider policy added timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `updatedBy` BIGINT UNSIGNED NULL COMMENT 'User who last updated',

    -- NULL-safe uniqueness: MySQL treats NULL tenantID rows as distinct, which is
    -- the intended "one row per (org, provider) with an optional tenant" behaviour.
    UNIQUE KEY `unique_org_provider_tenant` (`organizationID`, `provider`, `tenantID`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_provider` (`provider`),
    INDEX `idx_isEnabled` (`isEnabled`),
    CONSTRAINT `fk_orgoauth_org`
        FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization OAuth provider policies';

-- ============================================================================
-- 📨 TABLE: tblOrganizationInvitations
-- Purpose: Pending member invitations
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationInvitations` (
    `invitationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique invitation identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'FK → tblOrganizations',
    `email` VARCHAR(255) NOT NULL COMMENT 'Invited email address',
    `role` ENUM('owner', 'admin', 'member', 'guest') NOT NULL DEFAULT 'member' COMMENT 'Role to grant on acceptance',
    `invitationToken` VARCHAR(128) NOT NULL UNIQUE COMMENT 'Primary invitation token (Organization.php::inviteMember)',
    `token` VARCHAR(128) NULL COMMENT 'Secondary token written by the members.php resend path (kept for consumer compatibility)',
    `invitedBy` BIGINT UNSIGNED NOT NULL COMMENT 'User who sent the invitation',
    `message` TEXT NULL COMMENT 'Optional personal message',
    `status` ENUM('pending', 'accepted', 'declined', 'expired', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Invitation status',
    `acceptedBy` BIGINT UNSIGNED NULL COMMENT 'User who accepted (if applicable)',
    `acceptedAt` DATETIME NULL COMMENT 'Acceptance timestamp',
    `expiresAt` DATETIME NOT NULL COMMENT 'Invitation expiry timestamp',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Invitation sent timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_email` (`email`),
    INDEX `idx_token` (`token`),
    INDEX `idx_status` (`status`),
    INDEX `idx_expiresAt` (`expiresAt`),
    CONSTRAINT `fk_orginvites_org`
        FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_orginvites_user`
        FOREIGN KEY (`invitedBy`) REFERENCES `tblUsers`(`userID`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization member invitations';

-- ============================================================================
-- 🔄 BACK-REFERENCE FK: tblUsers.organizationID → tblOrganizations
-- ----------------------------------------------------------------------------
-- Migration 025 added tblUsers.organizationID (used by Auth::register and
-- Organization::create's UPDATE), but did not add a FK because tblOrganizations
-- did not yet exist. Now that it does, wire up the constraint — but ONLY if the
-- column exists and the FK is not already present, so this migration stays
-- idempotent and does not fail on partially-migrated databases.
-- @see https://dev.mysql.com/doc/refman/8.4/en/prepared-statements.html
-- ============================================================================
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblUsers'
      AND CONSTRAINT_NAME = 'fk_users_organization'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblUsers'
      AND COLUMN_NAME = 'organizationID'
);
SET @sql = IF(
    @fk_exists = 0 AND @col_exists = 1,
    'ALTER TABLE `tblUsers`
        ADD CONSTRAINT `fk_users_organization`
        FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`)
        ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "tblUsers.fk_users_organization already present or organizationID column missing — skipped" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 🔄 OPTIONAL: tenant columns on tblUserLinkedAccounts (guarded)
-- ----------------------------------------------------------------------------
-- The archived org draft added tenantID/tenantName to tblUserLinkedAccounts.
-- That table is NOT part of the canonical schema yet (no migration creates it),
-- so only patch it IF it exists AND the columns are absent. On a canonical
-- install this is a harmless no-op; if tblUserLinkedAccounts is added later, its
-- own migration should carry these columns.
-- ============================================================================
SET @ula_table = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblUserLinkedAccounts'
);
SET @ula_tenant = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblUserLinkedAccounts'
      AND COLUMN_NAME = 'tenantID'
);
SET @sql = IF(
    @ula_table = 1 AND @ula_tenant = 0,
    'ALTER TABLE `tblUserLinkedAccounts`
        ADD COLUMN `tenantID` VARCHAR(255) NULL COMMENT "OAuth tenant ID",
        ADD COLUMN `tenantName` VARCHAR(255) NULL COMMENT "OAuth tenant name",
        ADD INDEX `idx_tenantID` (`tenantID`)',
    'SELECT "tblUserLinkedAccounts tenant columns skipped (table absent or already patched)" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 📝 DEFAULT ORGANIZATION SETTINGS (global tblSettings)
-- ----------------------------------------------------------------------------
-- Uses the CANONICAL tblSettings columns (settingKey, settingValue, settingType,
-- settingCategory, isSensitive, description) — NOT the settingGroup/displayOrder
-- columns from the archived draft, which no longer exist. ON DUPLICATE keeps it
-- idempotent. organizations.invitation_expiry_days is read by
-- Organization::inviteMember via getSetting().
-- ============================================================================
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`) VALUES
('organizations.enabled', '1', 'boolean', 'organizations', 0, 'Enable organization / enterprise accounts'),
('organizations.domain_verification_required', '1', 'boolean', 'organizations', 0, 'Require domain verification before an organization becomes active'),
('organizations.auto_enroll', '1', 'boolean', 'organizations', 0, 'Auto-enroll users registering with a verified organization domain'),
('organizations.max_free_users', '10', 'integer', 'organizations', 0, 'Maximum users in the free organization tier'),
('organizations.invitation_expiry_days', '7', 'integer', 'organizations', 0, 'Days before an organization invitation expires')
ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`);

-- ============================================================================
-- 📧 ORGANIZATION EMAIL TEMPLATES (canonical tblEmailTemplates columns)
-- ============================================================================
INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `variables`, `description`, `locale`) VALUES
('org_invitation', 'Organization Invitation', 'You are invited to join {{organizationName}} on SIGNula',
'<h1>Join {{organizationName}}</h1><p>Hello,</p><p>{{inviterName}} has invited you to join <strong>{{organizationName}}</strong> on SIGNula as a {{role}}.</p><p>{{personalMessage}}</p><p><a href="{{acceptUrl}}" style="display:inline-block;padding:12px 24px;background:#667eea;color:#ffffff;text-decoration:none;border-radius:6px;">Accept Invitation</a></p><p>This invitation will expire in {{expiryDays}} days.</p>',
'Hello, {{inviterName}} has invited you to join {{organizationName}} on SIGNula as a {{role}}. {{personalMessage}} Accept: {{acceptUrl}} (expires in {{expiryDays}} days).',
'["organizationName","inviterName","role","personalMessage","acceptUrl","expiryDays"]', 'Organization invitation email', 'en_US'),
('org_welcome', 'Welcome to Organization', 'Welcome to {{organizationName}}',
'<h1>Welcome to {{organizationName}}!</h1><p>Hello {{displayName}},</p><p>You are now a member of <strong>{{organizationName}}</strong> on SIGNula.</p><p>Your role: <strong>{{role}}</strong></p><p><a href="{{dashboardUrl}}">Go to Dashboard</a></p>',
'Welcome to {{organizationName}}! Hello {{displayName}}, you are now a member with role: {{role}}. Dashboard: {{dashboardUrl}}',
'["organizationName","displayName","role","dashboardUrl"]', 'Organization welcome email', 'en_US')
ON DUPLICATE KEY UPDATE `bodyHTML` = VALUES(`bodyHTML`), `bodyText` = VALUES(`bodyText`);

-- ============================================================================
-- ✅ FINALIZATION
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Migration 028_organizations.sql completed successfully' AS Result;
