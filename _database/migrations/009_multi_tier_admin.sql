-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
--
-- Migration 009: Multi-Tier Admin System
--
-- Purpose: Implement multi-tier admin system with:
--   - Super Admins (SIGNula owners)
--   - Partner Root Admins (organization owners)
--   - Partner Team Members (admin, developer, support, finance roles)
--   - Feature toggle system (global and per-partner)
--   - Complete partner isolation
--
-- Version: 2.2.0-beta
-- Date: 2026-02-04
--
-- ============================================================================

USE signula;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. ADD SUPER ADMIN FLAG TO USERS TABLE
-- ============================================================================

ALTER TABLE tblUsers
ADD COLUMN `isSuperAdmin` BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Super admin flag for SIGNula system owners (global control)'
    AFTER isAdmin;

-- Add index for super admin queries
CREATE INDEX idx_super_admin ON tblUsers(isSuperAdmin);

-- ============================================================================
-- 2. PARTNER TEAM MEMBERS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblPartnerTeamMembers` (
    `memberID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `userID` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('root-admin', 'admin', 'developer', 'support', 'finance') NOT NULL DEFAULT 'developer'
        COMMENT 'Team member role within organization',
    `isRootAdmin` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Root admin flag - only ONE per partner (organization owner)',
    `invitedBy` BIGINT UNSIGNED NULL
        COMMENT 'User ID who invited this team member',
    `invitedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `joinedAt` DATETIME NULL
        COMMENT 'When member accepted invitation',
    `status` ENUM('pending', 'active', 'suspended', 'removed') NOT NULL DEFAULT 'active',
    `permissions` JSON NULL
        COMMENT 'Additional granular permissions (future use)',
    `notes` TEXT NULL,
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_team_partner FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    CONSTRAINT fk_team_user FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE,
    CONSTRAINT fk_team_invited_by FOREIGN KEY (invitedBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Unique constraint: user can only have one role per partner
    UNIQUE KEY uk_partner_user (partnerID, userID),

    -- Indexes
    INDEX idx_partner (partnerID),
    INDEX idx_user (userID),
    INDEX idx_role (role),
    INDEX idx_status (status),
    INDEX idx_root_admin (isRootAdmin)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner organization team members with roles';

-- ============================================================================
-- 3. FEATURE TOGGLES TABLE (GLOBAL)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblFeatureToggles` (
    `featureID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `featureKey` VARCHAR(100) NOT NULL UNIQUE
        COMMENT 'Unique feature identifier (e.g., rate_limiting, api_keys)',
    `featureName` VARCHAR(255) NOT NULL
        COMMENT 'Human-readable feature name',
    `featureDescription` TEXT NULL
        COMMENT 'Description of what this feature does',
    `isEnabledGlobally` BOOLEAN NOT NULL DEFAULT TRUE
        COMMENT 'Global enable/disable for ALL partners',
    `category` VARCHAR(50) NULL
        COMMENT 'Feature category (security, analytics, billing, etc.)',
    `requiresMigration` VARCHAR(50) NULL
        COMMENT 'Migration number required (e.g., 007, 008)',
    `affectsAPI` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether this feature affects API functionality',
    `canPartnersToggle` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether partners can toggle this for themselves',
    `modifiedBy` BIGINT UNSIGNED NULL
        COMMENT 'Last super admin who modified this',
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Key
    CONSTRAINT fk_feature_modified_by FOREIGN KEY (modifiedBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_enabled (isEnabledGlobally),
    INDEX idx_category (category),
    INDEX idx_can_toggle (canPartnersToggle)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Global feature toggle system for SIGNula';

-- ============================================================================
-- 4. PARTNER-SPECIFIC FEATURE OVERRIDES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblPartnerFeatures` (
    `partnerFeatureID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `featureID` BIGINT UNSIGNED NOT NULL,
    `isEnabled` BOOLEAN NOT NULL DEFAULT TRUE
        COMMENT 'Partner-specific override of global setting',
    `enabledBy` BIGINT UNSIGNED NULL
        COMMENT 'User who enabled/disabled (partner admin or super admin)',
    `reason` TEXT NULL
        COMMENT 'Reason for enabling/disabling',
    `expiresAt` DATETIME NULL
        COMMENT 'Optional expiration for temporary feature access',
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_pf_partner FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    CONSTRAINT fk_pf_feature FOREIGN KEY (featureID) REFERENCES tblFeatureToggles(featureID) ON DELETE CASCADE,
    CONSTRAINT fk_pf_enabled_by FOREIGN KEY (enabledBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Unique constraint: one override per partner per feature
    UNIQUE KEY uk_partner_feature (partnerID, featureID),

    -- Indexes
    INDEX idx_partner (partnerID),
    INDEX idx_feature (featureID),
    INDEX idx_enabled (isEnabled),
    INDEX idx_expires (expiresAt)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Partner-specific feature toggle overrides';

-- ============================================================================
-- 5. TEAM MEMBER INVITATIONS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblTeamInvitations` (
    `invitationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `partnerID` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(255) NOT NULL
        COMMENT 'Email address of invitee',
    `role` ENUM('admin', 'developer', 'support', 'finance') NOT NULL DEFAULT 'developer'
        COMMENT 'Role to assign when invitation is accepted',
    `invitedBy` BIGINT UNSIGNED NOT NULL
        COMMENT 'Partner admin who sent invitation',
    `invitationToken` VARCHAR(64) NOT NULL UNIQUE
        COMMENT 'Secure token for accepting invitation',
    `status` ENUM('pending', 'accepted', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
    `expiresAt` DATETIME NOT NULL
        COMMENT 'Invitation expiration (default 7 days)',
    `acceptedAt` DATETIME NULL,
    `acceptedBy` BIGINT UNSIGNED NULL
        COMMENT 'User ID who accepted (links to tblUsers)',
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_inv_partner FOREIGN KEY (partnerID) REFERENCES tblPartners(partnerID) ON DELETE CASCADE,
    CONSTRAINT fk_inv_invited_by FOREIGN KEY (invitedBy) REFERENCES tblUsers(userID) ON DELETE CASCADE,
    CONSTRAINT fk_inv_accepted_by FOREIGN KEY (acceptedBy) REFERENCES tblUsers(userID) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_partner (partnerID),
    INDEX idx_email (email),
    INDEX idx_token (invitationToken),
    INDEX idx_status (status),
    INDEX idx_expires (expiresAt)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Team member invitation system';

-- ============================================================================
-- 6. ADMIN ACTION AUDIT LOG
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblAdminAuditLog` (
    `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `userID` BIGINT UNSIGNED NOT NULL
        COMMENT 'Admin user who performed action',
    `adminLevel` ENUM('super-admin', 'partner-admin') NOT NULL
        COMMENT 'Level of admin performing action',
    `action` VARCHAR(100) NOT NULL
        COMMENT 'Action performed (e.g., toggle_feature, invite_member)',
    `targetType` VARCHAR(50) NULL
        COMMENT 'Type of target (partner, user, feature, etc.)',
    `targetID` BIGINT UNSIGNED NULL
        COMMENT 'ID of affected entity',
    `changes` JSON NULL
        COMMENT 'Details of changes made',
    `ipAddress` VARCHAR(45) NULL,
    `userAgent` TEXT NULL,
    `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Key
    CONSTRAINT fk_audit_user FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_user (userID),
    INDEX idx_action (action),
    INDEX idx_created (createdAt),
    INDEX idx_target (targetType, targetID)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit log for all admin actions';

-- ============================================================================
-- 7. INSERT DEFAULT FEATURES
-- ============================================================================

INSERT INTO tblFeatureToggles (featureKey, featureName, featureDescription, isEnabledGlobally, category, requiresMigration, affectsAPI, canPartnersToggle) VALUES
-- Security Features
('rate_limiting', 'Rate Limiting', 'API rate limiting and protection against abuse', TRUE, 'security', '007', TRUE, FALSE),
('api_keys', 'API Key Management', 'Partner API key generation and management', TRUE, 'security', '008', TRUE, FALSE),
('ip_whitelisting', 'IP Whitelisting', 'Restrict API access to specific IP addresses', TRUE, 'security', '008', TRUE, TRUE),
('progressive_blocking', 'Progressive Blocking', 'Automatic escalating blocks for violations', TRUE, 'security', '007', TRUE, FALSE),

-- Authentication Features
('oauth_integration', 'OAuth Integration', 'Third-party OAuth provider support', TRUE, 'authentication', '003', FALSE, FALSE),
('webauthn', 'WebAuthn/PassKeys', 'Passwordless authentication with FIDO2', TRUE, 'authentication', '005', FALSE, FALSE),
('mfa', 'Multi-Factor Authentication', 'TOTP and email-based MFA', TRUE, 'authentication', NULL, FALSE, FALSE),

-- Email Features
('email_system', 'Email System', 'Transactional email with templates and tracking', TRUE, 'email', '001', FALSE, TRUE),
('email_campaigns', 'Email Campaigns', 'Marketing campaigns and drip sequences', TRUE, 'email', '003', FALSE, TRUE),
('delegate_mailbox', 'Delegate Mailbox', 'Send emails via OAuth shared mailboxes', TRUE, 'email', '006', FALSE, TRUE),

-- Analytics & Reporting
('usage_analytics', 'Usage Analytics', 'Detailed API usage statistics and reporting', TRUE, 'analytics', NULL, FALSE, TRUE),
('audit_logs', 'Audit Logs', 'Complete audit trail of all actions', TRUE, 'analytics', NULL, FALSE, FALSE),

-- Content Features
('blog_system', 'Blog System', 'News and blog post management', TRUE, 'content', '005', FALSE, TRUE),
('support_system', 'Support System', 'Ticket-based customer support', TRUE, 'content', '006', FALSE, TRUE);

-- ============================================================================
-- 8. UPDATE EXISTING PARTNERS TO HAVE ROOT ADMIN
-- ============================================================================

-- For each existing partner, create a root admin entry
-- This assumes the partner email matches a user in tblUsers
INSERT INTO tblPartnerTeamMembers (partnerID, userID, role, isRootAdmin, status)
SELECT p.partnerID, u.userID, 'root-admin', TRUE, 'active'
FROM tblPartners p
JOIN tblUsers u ON p.email = u.email
WHERE NOT EXISTS (
    SELECT 1 FROM tblPartnerTeamMembers tm
    WHERE tm.partnerID = p.partnerID AND tm.isRootAdmin = TRUE
);

-- ============================================================================
-- 9. ADD SETTINGS FOR PARTNER DIRECTORY
-- ============================================================================

INSERT INTO tblSettings (settingCategory, settingKey, settingValue, dataType, isPublic, isSensitive, description, modifiedBy)
VALUES
('partners', 'enable_public_directory', '0', 'boolean', 0, 0, 'Enable public partner directory on website', NULL),
('partners', 'directory_approval_required', '1', 'boolean', 0, 0, 'Require partners to approve before appearing in directory', NULL),
('partners', 'max_team_members_free', '5', 'integer', 0, 0, 'Maximum team members for free tier partners', NULL),
('partners', 'max_team_members_basic', '10', 'integer', 0, 0, 'Maximum team members for basic tier partners', NULL),
('partners', 'max_team_members_premium', '25', 'integer', 0, 0, 'Maximum team members for premium tier partners', NULL),
('partners', 'max_team_members_enterprise', '0', 'integer', 0, 0, 'Maximum team members for enterprise tier (0 = unlimited)', NULL),
('invitations', 'invitation_expiry_days', '7', 'integer', 0, 0, 'Days until team invitations expire', NULL);

-- ============================================================================
-- 10. ADD PARTNER VISIBILITY SETTINGS
-- ============================================================================

ALTER TABLE tblPartners
ADD COLUMN `showInDirectory` BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Whether partner appears in public directory (if enabled)'
    AFTER status,
ADD COLUMN `directoryLogo` VARCHAR(255) NULL
    COMMENT 'URL to partner logo for directory'
    AFTER showInDirectory,
ADD COLUMN `directoryDescription` TEXT NULL
    COMMENT 'Public description for directory listing'
    AFTER directoryLogo;

-- ============================================================================
-- 11. CREATE TRIGGER FOR ROOT ADMIN ENFORCEMENT
-- ============================================================================

DELIMITER //

CREATE TRIGGER before_team_member_insert
BEFORE INSERT ON tblPartnerTeamMembers
FOR EACH ROW
BEGIN
    -- Ensure only ONE root admin per partner
    IF NEW.isRootAdmin = TRUE THEN
        -- Check if root admin already exists
        IF EXISTS (
            SELECT 1 FROM tblPartnerTeamMembers
            WHERE partnerID = NEW.partnerID
            AND isRootAdmin = TRUE
            AND memberID != NEW.memberID
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Partner already has a root admin. Transfer ownership first.';
        END IF;
        -- Root admin must have root-admin role
        SET NEW.role = 'root-admin';
    END IF;
END//

CREATE TRIGGER before_team_member_update
BEFORE UPDATE ON tblPartnerTeamMembers
FOR EACH ROW
BEGIN
    -- Ensure only ONE root admin per partner
    IF NEW.isRootAdmin = TRUE AND OLD.isRootAdmin = FALSE THEN
        -- Transferring root admin
        IF EXISTS (
            SELECT 1 FROM tblPartnerTeamMembers
            WHERE partnerID = NEW.partnerID
            AND isRootAdmin = TRUE
            AND memberID != NEW.memberID
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Partner already has a root admin. Remove existing root admin first.';
        END IF;
        -- Root admin must have root-admin role
        SET NEW.role = 'root-admin';
    END IF;

    -- Cannot remove last root admin
    IF OLD.isRootAdmin = TRUE AND NEW.isRootAdmin = FALSE THEN
        IF NOT EXISTS (
            SELECT 1 FROM tblPartnerTeamMembers
            WHERE partnerID = NEW.partnerID
            AND isRootAdmin = TRUE
            AND memberID != NEW.memberID
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot remove last root admin. Transfer ownership first.';
        END IF;
    END IF;
END//

DELIMITER ;

-- ============================================================================
-- 12. CREATE VIEWS FOR EASY ACCESS
-- ============================================================================

CREATE OR REPLACE VIEW vwPartnerAdmins AS
SELECT
    tm.memberID,
    tm.partnerID,
    p.companyName,
    tm.userID,
    u.username,
    u.email,
    tm.role,
    tm.isRootAdmin,
    tm.status,
    tm.createdAt
FROM tblPartnerTeamMembers tm
JOIN tblPartners p ON tm.partnerID = p.partnerID
JOIN tblUsers u ON tm.userID = u.userID
WHERE tm.role IN ('root-admin', 'admin')
AND tm.status = 'active';

CREATE OR REPLACE VIEW vwActiveFeatures AS
SELECT
    f.featureID,
    f.featureKey,
    f.featureName,
    f.isEnabledGlobally,
    f.category,
    COUNT(pf.partnerFeatureID) as partnersWithOverride,
    SUM(CASE WHEN pf.isEnabled = TRUE THEN 1 ELSE 0 END) as partnersEnabled
FROM tblFeatureToggles f
LEFT JOIN tblPartnerFeatures pf ON f.featureID = pf.featureID
GROUP BY f.featureID;

-- ============================================================================
-- CLEANUP
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Verify tables created
SELECT 'tblPartnerTeamMembers' as TableName, COUNT(*) as RowCount FROM tblPartnerTeamMembers
UNION ALL
SELECT 'tblFeatureToggles', COUNT(*) FROM tblFeatureToggles
UNION ALL
SELECT 'tblPartnerFeatures', COUNT(*) FROM tblPartnerFeatures
UNION ALL
SELECT 'tblTeamInvitations', COUNT(*) FROM tblTeamInvitations
UNION ALL
SELECT 'tblAdminAuditLog', COUNT(*) FROM tblAdminAuditLog;

-- Verify super admin column added
SELECT COUNT(*) as SuperAdminCount FROM tblUsers WHERE isSuperAdmin = TRUE;

-- Verify root admins assigned
SELECT p.companyName, u.username, tm.role, tm.isRootAdmin
FROM tblPartnerTeamMembers tm
JOIN tblPartners p ON tm.partnerID = p.partnerID
JOIN tblUsers u ON tm.userID = u.userID
WHERE tm.isRootAdmin = TRUE;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================

SELECT
    'Migration 009: Multi-Tier Admin System completed successfully!' as Status,
    (SELECT COUNT(*) FROM tblFeatureToggles) as FeaturesCreated,
    (SELECT COUNT(*) FROM tblPartnerTeamMembers WHERE isRootAdmin = TRUE) as RootAdminsAssigned;
