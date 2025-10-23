-- ============================================================================
-- 📁 SIGNula - Organization Support Migration
-- ============================================================================
-- Version: 1.1.0
-- Description: Add organization/enterprise account support
-- Adds support for company accounts with domain verification,
-- organization admins, and policy management
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 🏢 TABLE: tblOrganizations
-- Purpose: Organization/company accounts
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizations` (
    `organizationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique organization identifier',
    `organizationUUID` CHAR(36) NOT NULL UNIQUE COMMENT 'Universal unique identifier',
    `organizationName` VARCHAR(255) NOT NULL COMMENT 'Organization name',
    `organizationSlug` VARCHAR(100) NOT NULL UNIQUE COMMENT 'URL-friendly slug',
    `organizationType` ENUM('company', 'nonprofit', 'education', 'government', 'other') NOT NULL DEFAULT 'company' COMMENT 'Organization type',
    `logo` TEXT NULL COMMENT 'Organization logo URL or base64',
    `website` VARCHAR(500) NULL COMMENT 'Organization website',
    `industry` VARCHAR(100) NULL COMMENT 'Industry/sector',
    `employeeCount` ENUM('1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5001+') NULL COMMENT 'Number of employees',
    `contactEmail` VARCHAR(255) NULL COMMENT 'Primary contact email',
    `contactPhone` VARCHAR(20) NULL COMMENT 'Primary contact phone',
    `addressLine1` VARCHAR(255) NULL COMMENT 'Address line 1',
    `addressLine2` VARCHAR(255) NULL COMMENT 'Address line 2',
    `city` VARCHAR(100) NULL COMMENT 'City',
    `state` VARCHAR(100) NULL COMMENT 'State/Province',
    `postalCode` VARCHAR(20) NULL COMMENT 'Postal/ZIP code',
    `country` VARCHAR(100) NULL COMMENT 'Country',
    `taxID` VARCHAR(50) NULL COMMENT 'Tax ID/EIN (encrypted)',
    `billingEmail` VARCHAR(255) NULL COMMENT 'Billing contact email',
    `accountStatus` ENUM('active', 'suspended', 'pending_verification', 'deleted') NOT NULL DEFAULT 'pending_verification' COMMENT 'Organization status',
    `subscriptionTier` ENUM('free', 'team', 'business', 'enterprise') NOT NULL DEFAULT 'free' COMMENT 'Subscription tier',
    `maxUsers` INT UNSIGNED DEFAULT 10 COMMENT 'Maximum allowed users (NULL = unlimited)',
    `isVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Domain verification status',
    `verifiedAt` DATETIME NULL COMMENT 'Verification timestamp',
    `createdBy` BIGINT UNSIGNED NULL COMMENT 'User who created the organization',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `deletedAt` DATETIME NULL COMMENT 'Soft delete timestamp',

    INDEX `idx_organizationSlug` (`organizationSlug`),
    INDEX `idx_accountStatus` (`accountStatus`),
    INDEX `idx_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organizations/companies';

-- ============================================================================
-- 🌐 TABLE: tblOrganizationDomains
-- Purpose: Verified email domains for organizations
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationDomains` (
    `domainID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique domain identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblOrganizations',
    `domain` VARCHAR(255) NOT NULL COMMENT 'Email domain (e.g., company.com)',
    `isVerified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Domain verification status',
    `verificationMethod` ENUM('dns', 'email', 'file', 'manual') NULL COMMENT 'How domain was verified',
    `verificationToken` VARCHAR(128) NULL COMMENT 'Verification token',
    `verifiedAt` DATETIME NULL COMMENT 'Verification timestamp',
    `verifiedBy` BIGINT UNSIGNED NULL COMMENT 'User who verified',
    `isPrimary` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Primary domain for organization',
    `autoEnroll` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Auto-enroll users with this domain',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Domain added timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_domain` (`domain`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_domain` (`domain`),
    INDEX `idx_isVerified` (`isVerified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization verified domains';

-- ============================================================================
-- 👥 TABLE: tblOrganizationMembers
-- Purpose: Link users to organizations with roles
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationMembers` (
    `memberID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique member record identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblOrganizations',
    `userID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblUsers',
    `role` ENUM('owner', 'admin', 'member', 'guest') NOT NULL DEFAULT 'member' COMMENT 'User role in organization',
    `department` VARCHAR(100) NULL COMMENT 'Department/team',
    `jobTitle` VARCHAR(100) NULL COMMENT 'Job title',
    `permissions` TEXT NULL COMMENT 'JSON array of specific permissions',
    `invitedBy` BIGINT UNSIGNED NULL COMMENT 'User who invited this member',
    `invitedAt` DATETIME NULL COMMENT 'Invitation timestamp',
    `joinedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Membership start timestamp',
    `status` ENUM('active', 'invited', 'suspended', 'left') NOT NULL DEFAULT 'invited' COMMENT 'Membership status',
    `leftAt` DATETIME NULL COMMENT 'When user left organization',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_org_user` (`organizationID`, `userID`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_userID` (`userID`),
    INDEX `idx_role` (`role`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization members';

-- ============================================================================
-- ⚙️ TABLE: tblOrganizationSettings
-- Purpose: Organization-specific settings and policies
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationSettings` (
    `settingID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique setting identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblOrganizations',
    `settingKey` VARCHAR(255) NOT NULL COMMENT 'Setting key',
    `settingValue` TEXT NULL COMMENT 'Setting value',
    `settingType` ENUM('string', 'integer', 'float', 'boolean', 'json', 'array') NOT NULL DEFAULT 'string' COMMENT 'Data type',
    `description` TEXT NULL COMMENT 'Setting description',
    `isEditable` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Can be edited by org admins',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Setting creation timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `updatedBy` BIGINT UNSIGNED NULL COMMENT 'User who last updated',

    FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_org_setting` (`organizationID`, `settingKey`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_settingKey` (`settingKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization settings';

-- ============================================================================
-- 🔗 TABLE: tblOrganizationOAuthProviders
-- Purpose: Allowed OAuth providers and tenants for organizations
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationOAuthProviders` (
    `providerID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique provider record identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblOrganizations',
    `provider` ENUM('google', 'microsoft', 'apple', 'facebook', 'instagram', 'linkedin', 'lastpass', 'yahoo', 'wordpress', 'amazon', 'paypal', 'openid', 'github', 'twitter') NOT NULL COMMENT 'OAuth provider',
    `isEnabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Provider enabled for organization',
    `tenantID` VARCHAR(255) NULL COMMENT 'Specific tenant ID (e.g., MS365 tenant)',
    `tenantName` VARCHAR(255) NULL COMMENT 'Tenant display name',
    `allowedDomains` TEXT NULL COMMENT 'JSON array of allowed domains for this provider',
    `requireTenant` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Require specific tenant match',
    `autoEnroll` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Auto-enroll users from this provider',
    `metadata` TEXT NULL COMMENT 'Additional provider-specific settings (JSON)',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Provider added timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    `updatedBy` BIGINT UNSIGNED NULL COMMENT 'User who last updated',

    FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `unique_org_provider_tenant` (`organizationID`, `provider`, `tenantID`),
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_provider` (`provider`),
    INDEX `idx_isEnabled` (`isEnabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization OAuth provider policies';

-- ============================================================================
-- 📨 TABLE: tblOrganizationInvitations
-- Purpose: Organization invitations for new members
-- ============================================================================
CREATE TABLE IF NOT EXISTS `tblOrganizationInvitations` (
    `invitationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique invitation identifier',
    `organizationID` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to tblOrganizations',
    `email` VARCHAR(255) NOT NULL COMMENT 'Invited email address',
    `role` ENUM('owner', 'admin', 'member', 'guest') NOT NULL DEFAULT 'member' COMMENT 'Invited role',
    `invitationToken` VARCHAR(128) NOT NULL UNIQUE COMMENT 'Invitation token',
    `invitedBy` BIGINT UNSIGNED NOT NULL COMMENT 'User who sent invitation',
    `message` TEXT NULL COMMENT 'Personal message with invitation',
    `status` ENUM('pending', 'accepted', 'declined', 'expired', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Invitation status',
    `acceptedBy` BIGINT UNSIGNED NULL COMMENT 'User who accepted (if applicable)',
    `acceptedAt` DATETIME NULL COMMENT 'Acceptance timestamp',
    `expiresAt` DATETIME NOT NULL COMMENT 'Invitation expiry',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Invitation sent timestamp',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`invitedBy`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_organizationID` (`organizationID`),
    INDEX `idx_email` (`email`),
    INDEX `idx_invitationToken` (`invitationToken`),
    INDEX `idx_status` (`status`),
    INDEX `idx_expiresAt` (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Organization invitations';

-- ============================================================================
-- 🔄 ALTER EXISTING TABLES
-- ============================================================================

-- Add organizationID to tblUsers
ALTER TABLE `tblUsers`
ADD COLUMN `organizationID` BIGINT UNSIGNED NULL COMMENT 'Organization membership' AFTER `userUUID`,
ADD INDEX `idx_organizationID` (`organizationID`),
ADD CONSTRAINT `fk_users_organization`
    FOREIGN KEY (`organizationID`) REFERENCES `tblOrganizations`(`organizationID`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- Add tenant information to tblUserLinkedAccounts
ALTER TABLE `tblUserLinkedAccounts`
ADD COLUMN `tenantID` VARCHAR(255) NULL COMMENT 'OAuth tenant ID' AFTER `providerUserID`,
ADD COLUMN `tenantName` VARCHAR(255) NULL COMMENT 'OAuth tenant name' AFTER `tenantID`,
ADD INDEX `idx_tenantID` (`tenantID`);

-- Update tblSettings to add new organizational settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingGroup`, `description`, `isEditable`, `displayOrder`) VALUES
('organizations.enabled', 'true', 'boolean', 'organizations', 'Enable organization accounts', TRUE, 100),
('organizations.domain_verification_required', 'true', 'boolean', 'organizations', 'Require domain verification for organizations', TRUE, 101),
('organizations.auto_enroll', 'true', 'boolean', 'organizations', 'Auto-enroll users with verified domain emails', TRUE, 102),
('organizations.max_free_users', '10', 'integer', 'organizations', 'Maximum users in free organization tier', TRUE, 103),
('email_verification.required', 'true', 'boolean', 'email', 'Require email verification before account use', TRUE, 40),
('email_verification.token_lifetime_days', '7', 'integer', 'email', 'Email verification token lifetime in days', TRUE, 41),
('email_verification.reminder_days', '3,5', 'string', 'email', 'Send reminder emails on these days (comma-separated)', TRUE, 42),
('email_verification.allow_login_unverified', 'true', 'boolean', 'email', 'Allow login before email verification', TRUE, 43)
ON DUPLICATE KEY UPDATE settingValue=VALUES(settingValue);

-- ============================================================================
-- 📊 VIEWS FOR ORGANIZATIONS
-- ============================================================================

-- View: Organization Members with User Details
CREATE OR REPLACE VIEW `vw_OrganizationMembers` AS
SELECT
    om.memberID,
    om.organizationID,
    o.organizationName,
    om.userID,
    u.username,
    u.email,
    u.displayName,
    u.firstName,
    u.lastName,
    om.role,
    om.department,
    om.jobTitle,
    om.status,
    om.joinedAt,
    COUNT(DISTINCT us.sessionID) as activeSessionsCount
FROM tblOrganizationMembers om
JOIN tblOrganizations o ON om.organizationID = o.organizationID
JOIN tblUsers u ON om.userID = u.userID
LEFT JOIN tblUserSessions us ON u.userID = us.userID AND us.isActive = TRUE AND us.expiresAt > NOW()
WHERE o.deletedAt IS NULL AND u.deletedAt IS NULL
GROUP BY om.memberID;

-- View: Organization Statistics
CREATE OR REPLACE VIEW `vw_OrganizationStats` AS
SELECT
    o.organizationID,
    o.organizationName,
    o.organizationSlug,
    o.accountStatus,
    o.subscriptionTier,
    o.maxUsers,
    COUNT(DISTINCT om.memberID) as totalMembers,
    COUNT(DISTINCT CASE WHEN om.status = 'active' THEN om.memberID END) as activeMembers,
    COUNT(DISTINCT CASE WHEN om.role = 'admin' THEN om.memberID END) as adminCount,
    COUNT(DISTINCT od.domainID) as verifiedDomainsCount,
    o.createdAt,
    o.isVerified
FROM tblOrganizations o
LEFT JOIN tblOrganizationMembers om ON o.organizationID = om.organizationID
LEFT JOIN tblOrganizationDomains od ON o.organizationID = od.organizationID AND od.isVerified = TRUE
WHERE o.deletedAt IS NULL
GROUP BY o.organizationID;

-- ============================================================================
-- 🔧 STORED PROCEDURES
-- ============================================================================

-- Procedure: Check if user belongs to organization
DELIMITER //
CREATE PROCEDURE sp_CheckUserOrganization(
    IN p_userID BIGINT UNSIGNED,
    IN p_organizationID BIGINT UNSIGNED,
    OUT p_isMember BOOLEAN,
    OUT p_role VARCHAR(20)
)
BEGIN
    SELECT
        CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END,
        MAX(role)
    INTO p_isMember, p_role
    FROM tblOrganizationMembers
    WHERE userID = p_userID
    AND organizationID = p_organizationID
    AND status = 'active';
END//
DELIMITER ;

-- Procedure: Get organization by email domain
DELIMITER //
CREATE PROCEDURE sp_GetOrganizationByEmailDomain(
    IN p_email VARCHAR(255)
)
BEGIN
    DECLARE v_domain VARCHAR(255);

    -- Extract domain from email
    SET v_domain = SUBSTRING_INDEX(p_email, '@', -1);

    -- Find organization with this domain
    SELECT o.*
    FROM tblOrganizations o
    JOIN tblOrganizationDomains od ON o.organizationID = od.organizationID
    WHERE od.domain = v_domain
    AND od.isVerified = TRUE
    AND o.accountStatus = 'active'
    AND o.deletedAt IS NULL
    LIMIT 1;
END//
DELIMITER ;

-- Procedure: Clean expired invitations
DELIMITER //
CREATE PROCEDURE sp_CleanExpiredInvitations()
BEGIN
    UPDATE tblOrganizationInvitations
    SET status = 'expired'
    WHERE status = 'pending'
    AND expiresAt < NOW();
END//
DELIMITER ;

-- ============================================================================
-- 📅 SCHEDULED EVENTS
-- ============================================================================

-- Event: Clean expired organization invitations daily
CREATE EVENT IF NOT EXISTS evt_CleanExpiredOrgInvitations
ON SCHEDULE EVERY 1 DAY
DO CALL sp_CleanExpiredInvitations();

-- ============================================================================
-- 🔐 DEFAULT ORGANIZATIONAL POLICIES
-- ============================================================================

-- Insert default email templates for organizations
INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `variables`, `description`, `locale`) VALUES
('org_invitation', 'Organization Invitation', 'You\'re invited to join {{organizationName}} on SIGNula',
'<h1>Join {{organizationName}}</h1><p>Hello,</p><p>{{inviterName}} has invited you to join <strong>{{organizationName}}</strong> on SIGNula as a {{role}}.</p><p>{{personalMessage}}</p><p><a href="{{acceptUrl}}" style="display:inline-block;padding:12px 24px;background:#667eea;color:white;text-decoration:none;border-radius:6px;">Accept Invitation</a></p><p>This invitation will expire in {{expiryDays}} days.</p>',
'Hello, {{inviterName}} has invited you to join {{organizationName}} on SIGNula as a {{role}}. {{personalMessage}} Accept invitation: {{acceptUrl}} This invitation expires in {{expiryDays}} days.',
'["organizationName", "inviterName", "role", "personalMessage", "acceptUrl", "expiryDays"]', 'Organization invitation email', 'en_US'),

('org_welcome', 'Welcome to Organization', 'Welcome to {{organizationName}}',
'<h1>Welcome to {{organizationName}}!</h1><p>Hello {{displayName}},</p><p>You are now a member of <strong>{{organizationName}}</strong> on SIGNula.</p><p>Your role: <strong>{{role}}</strong></p><p><a href="{{dashboardUrl}}">Go to Dashboard</a></p>',
'Welcome to {{organizationName}}! Hello {{displayName}}, You are now a member of {{organizationName}} with role: {{role}}. Dashboard: {{dashboardUrl}}',
'["organizationName", "displayName", "role", "dashboardUrl"]', 'Organization welcome email', 'en_US'),

('verification_reminder', 'Please Verify Your Email', 'Reminder: Verify your SIGNula email address',
'<h1>Email Verification Reminder</h1><p>Hello {{displayName}},</p><p>Your SIGNula account was created {{daysAgo}} days ago, but your email address is not yet verified.</p><p>Please verify your email to access all features:</p><p><a href="{{verificationUrl}}">Verify Email Address</a></p><p>Or use this code: <strong>{{verificationCode}}</strong></p><p>This link will expire in {{daysRemaining}} days.</p>',
'Hello {{displayName}}, Your SIGNula account needs email verification. Verify at: {{verificationUrl}} or use code: {{verificationCode}}. Link expires in {{daysRemaining}} days.',
'["displayName", "daysAgo", "verificationUrl", "verificationCode", "daysRemaining"]', 'Email verification reminder', 'en_US')
ON DUPLICATE KEY UPDATE bodyHTML=VALUES(bodyHTML), bodyText=VALUES(bodyText);

-- ============================================================================
-- ✅ Migration Complete
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Organization support migration completed successfully!' AS Status;
