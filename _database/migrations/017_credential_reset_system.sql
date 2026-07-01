-- ============================================================================
-- 🔐 Migration 017: Credential Reset System & Email Enhancements
-- ============================================================================
-- Version: 2.6.0-beta
-- Date: 2026-02-24
-- Description: Adds mass credential reset tracking, salt rotation history,
--              and email enhancement settings for HTML/AMP email support.
-- ============================================================================

-- 🔒 Record migration start
-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)

-- ============================================================================
-- 📋 Table: tblCredentialResets
-- ============================================================================
-- Tracks mass credential reset operations initiated by Global Admins.
-- Each row represents a single bulk reset event (e.g., security breach response).

CREATE TABLE IF NOT EXISTS `tblCredentialResets` (
    `resetID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique identifier for this reset operation',
    `resetUUID` CHAR(36) NOT NULL UNIQUE
        COMMENT 'Public UUID for the reset operation (RFC 4122)',
    `initiatedBy` BIGINT UNSIGNED NULL
        COMMENT 'Admin userID who triggered the reset (nullable for admin deletion)',
    `resetType` ENUM('mass_password_reset', 'salt_rotation', 'full_credential_reset') NOT NULL
        COMMENT 'Type of credential reset operation',
    `reason` TEXT NOT NULL
        COMMENT 'Admin-provided reason (e.g., security breach, policy change)',
    `scope` ENUM('all_users', 'filtered', 'specific_users') NOT NULL DEFAULT 'all_users'
        COMMENT 'Scope of the reset operation',
    `scopeFilter` JSON NULL
        COMMENT 'Filter criteria for filtered scope (status, tier, date range)',
    `totalUsers` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Total number of users affected',
    `processedUsers` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of users processed so far',
    `emailsSent` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of notification emails queued',
    `emailsFailed` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of notification emails that failed to queue',
    `status` ENUM('pending', 'in_progress', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'
        COMMENT 'Current status of the reset operation',
    `previousSalt` TEXT NULL
        COMMENT 'Previous global salt value (before rotation, encrypted)',
    `newSalt` TEXT NULL
        COMMENT 'New global salt value (after rotation, encrypted)',
    `errorLog` JSON NULL
        COMMENT 'Array of errors encountered during processing',
    `completedAt` DATETIME NULL
        COMMENT 'Timestamp when operation completed',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Timestamp when operation was initiated',
    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'Last update timestamp',

    INDEX `idx_cr_status` (`status`),
    INDEX `idx_cr_initiated_by` (`initiatedBy`),
    INDEX `idx_cr_reset_type` (`resetType`),
    INDEX `idx_cr_created_at` (`createdAt`),

    CONSTRAINT `fk_cr_initiated_by`
        FOREIGN KEY (`initiatedBy`) REFERENCES `tblUsers` (`userID`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks mass credential reset operations';

-- ============================================================================
-- 📋 Table: tblCredentialResetUsers
-- ============================================================================
-- Tracks individual user status within a mass credential reset operation.

CREATE TABLE IF NOT EXISTS `tblCredentialResetUsers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Row identifier',
    `resetID` BIGINT UNSIGNED NOT NULL
        COMMENT 'FK to tblCredentialResets',
    `userID` BIGINT UNSIGNED NOT NULL
        COMMENT 'FK to tblUsers',
    `passwordInvalidated` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether password was invalidated',
    `saltRotated` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether salt was rotated for this user',
    `emailSent` BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Whether notification email was sent',
    `emailError` TEXT NULL
        COMMENT 'Email sending error message if failed',
    `passwordChangedAt` DATETIME NULL
        COMMENT 'When user completed their password change',
    `processedAt` DATETIME NULL
        COMMENT 'When this user was processed',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Row creation timestamp',

    UNIQUE KEY `uk_reset_user` (`resetID`, `userID`),
    INDEX `idx_cru_user_id` (`userID`),
    INDEX `idx_cru_email_sent` (`emailSent`),
    INDEX `idx_cru_password_changed` (`passwordChangedAt`),

    CONSTRAINT `fk_cru_reset_id`
        FOREIGN KEY (`resetID`) REFERENCES `tblCredentialResets` (`resetID`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_cru_user_id`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual user status within credential reset operations';

-- ============================================================================
-- 📋 Table: tblSaltRotationHistory
-- ============================================================================
-- Maintains a history of all salt rotations for auditing and rollback purposes.

CREATE TABLE IF NOT EXISTS `tblSaltRotationHistory` (
    `rotationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique rotation identifier',
    `resetID` BIGINT UNSIGNED NULL
        COMMENT 'FK to tblCredentialResets (NULL if standalone rotation)',
    `previousSalt` TEXT NOT NULL
        COMMENT 'Previous salt value (encrypted)',
    `newSalt` TEXT NOT NULL
        COMMENT 'New salt value (encrypted)',
    `rotatedBy` BIGINT UNSIGNED NOT NULL
        COMMENT 'Admin userID who performed the rotation',
    `reason` TEXT NOT NULL
        COMMENT 'Reason for rotation',
    `affectedUsers` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of users affected',
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Rotation timestamp',

    INDEX `idx_srh_rotated_by` (`rotatedBy`),
    INDEX `idx_srh_created_at` (`createdAt`),

    CONSTRAINT `fk_srh_reset_id`
        FOREIGN KEY (`resetID`) REFERENCES `tblCredentialResets` (`resetID`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_srh_rotated_by`
        FOREIGN KEY (`rotatedBy`) REFERENCES `tblUsers` (`userID`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for salt rotation events';

-- ============================================================================
-- ⚙️ New Settings: Credential Reset & Email Enhancements
-- ============================================================================

INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, isSensitive, isEditable, description, defaultValue) VALUES
-- 🔐 Credential Reset Settings
('security.credential_reset.batch_size', '100', 'integer', 'security', FALSE, TRUE,
 'Number of users to process per batch during mass credential reset', '100'),
('security.credential_reset.email_priority', '1', 'integer', 'security', FALSE, TRUE,
 'Email priority for credential reset notifications (1=highest, 10=lowest)', '1'),
('security.credential_reset.token_expiry_hours', '72', 'integer', 'security', FALSE, TRUE,
 'Hours before credential reset tokens expire', '72'),
('security.credential_reset.require_confirmation', '1', 'boolean', 'security', FALSE, TRUE,
 'Require admin confirmation before executing mass credential reset', '1'),
('security.credential_reset.invalidate_sessions', '1', 'boolean', 'security', FALSE, TRUE,
 'Invalidate all active sessions when mass credential reset is triggered', '1'),
('security.global_salt', '', 'encrypted', 'security', TRUE, TRUE,
 'Global application salt used for additional password security layer', ''),

-- 📧 Email Enhancement Settings
('email.html.enabled', '1', 'boolean', 'email', FALSE, TRUE,
 'Enable HTML email support (multipart MIME)', '1'),
('email.amp.enabled', '0', 'boolean', 'email', FALSE, TRUE,
 'Enable AMP for Email support (interactive emails)', '0'),
('email.amp.sender_verification_url', '', 'string', 'email', FALSE, TRUE,
 'AMP sender verification URL for Gmail AMP cache', ''),
('email.default_template_wrapper', 'modern', 'string', 'email', FALSE, TRUE,
 'Default HTML email wrapper template style (modern, minimal, branded)', 'modern'),
('email.preheader.enabled', '1', 'boolean', 'email', FALSE, TRUE,
 'Enable preheader text in HTML emails', '1'),
('email.unsubscribe.header_enabled', '1', 'boolean', 'email', FALSE, TRUE,
 'Include List-Unsubscribe header in marketing emails', '1'),
('email.dkim.enabled', '0', 'boolean', 'email', FALSE, TRUE,
 'Enable DKIM signing for outbound emails', '0'),
('email.dkim.selector', '', 'string', 'email', FALSE, TRUE,
 'DKIM selector for DNS record lookup', ''),
('email.dkim.private_key', '', 'encrypted', 'email', TRUE, TRUE,
 'DKIM private key for email signing', '')
ON DUPLICATE KEY UPDATE updatedAt = NOW();

-- ============================================================================
-- 📧 Email Templates: Security Breach & Mass Password Reset
-- ============================================================================

INSERT INTO tblEmailTemplates (templateKey, templateName, description, subject, bodyHTML, bodyText, category, trackingEnabled, requiredVariables, isActive) VALUES
-- 🚨 Security Breach Alert Template
('security_breach_alert', 'Security Breach Alert',
 'Notification sent to users during a security incident requiring credential reset',
 '🔒 Important Security Alert - Action Required for Your {{appName}} Account',
 '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>Security Alert</title>
<!--[if mso]>
<noscript>
<xml>
<o:OfficeDocumentSettings>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
</noscript>
<![endif]-->
<style>
:root { color-scheme: light dark; supported-color-schemes: light dark; }
body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
img { border: 0; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
a { color: #2563eb; text-decoration: none; }
.container { max-width: 600px; margin: 0 auto; }
.header { background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); padding: 30px 40px; text-align: center; }
.header h1 { color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 24px; margin: 0; }
.content { background-color: #ffffff; padding: 40px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #1f2937; }
.alert-box { background-color: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #dc2626; border-radius: 8px; padding: 20px; margin: 20px 0; }
.alert-box h3 { color: #991b1b; margin: 0 0 10px 0; font-size: 18px; }
.btn { display: inline-block; background-color: #dc2626; color: #ffffff !important; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; text-decoration: none; margin: 20px 0; }
.btn:hover { background-color: #b91c1c; }
.info-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
.info-table td { padding: 10px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
.info-table td:first-child { font-weight: 600; color: #6b7280; width: 140px; }
.footer { background-color: #f9fafb; padding: 24px 40px; text-align: center; font-size: 12px; color: #9ca3af; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
@media (prefers-color-scheme: dark) { .content { background-color: #1f2937 !important; color: #e5e7eb !important; } .alert-box { background-color: #451a1a !important; border-color: #7f1d1d !important; } .footer { background-color: #111827 !important; color: #6b7280 !important; } }
@media only screen and (max-width: 620px) { .container { width: 100% !important; } .content { padding: 24px !important; } .header { padding: 20px 24px !important; } }
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
<tr><td align="center" style="padding: 40px 20px;">
<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
<tr><td class="header">
<h1>&#x1F6A8; Security Alert</h1>
</td></tr>
<tr><td class="content">
<p>Dear {{displayName}},</p>
<div class="alert-box">
<h3>&#x26A0;&#xFE0F; Your account security requires immediate attention</h3>
<p style="margin: 0;">As a precautionary measure, we are requiring all users to reset their passwords. <strong>Your current password has been invalidated</strong> and you must set a new one to continue accessing your account.</p>
</div>
<p><strong>What happened:</strong></p>
<p>{{breachDescription}}</p>
<p><strong>What you need to do:</strong></p>
<ol>
<li>Click the button below to reset your password</li>
<li>Choose a strong, unique password you have not used before</li>
<li>Enable two-factor authentication if not already active</li>
<li>Review your recent account activity after logging in</li>
</ol>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center">
<a href="{{resetUrl}}" class="btn" style="color: #ffffff;">Reset Your Password Now</a>
</td></tr>
</table>
<table class="info-table" role="presentation">
<tr><td>Reset Link Expires</td><td>{{expiryTime}}</td></tr>
<tr><td>Your Email</td><td>{{email}}</td></tr>
<tr><td>Incident Reference</td><td>{{incidentRef}}</td></tr>
</table>
<p style="font-size: 14px; color: #6b7280;">If you did not request this change, please contact our support team immediately. This link will expire in {{expiryHours}} hours.</p>
<p style="font-size: 13px; color: #9ca3af; margin-top: 24px;">If the button above does not work, copy and paste this URL into your browser:<br><a href="{{resetUrl}}" style="color: #2563eb; word-break: break-all;">{{resetUrl}}</a></p>
</td></tr>
<tr><td class="footer">
<p>&copy; {{currentYear}} {{appName}}. All rights reserved.</p>
<p>This is an automated security notification. Please do not reply to this email.</p>
<p style="margin-top: 8px;"><a href="{{supportUrl}}" style="color: #6b7280;">Contact Support</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
 'SECURITY ALERT - Action Required for Your {{appName}} Account

Dear {{displayName}},

YOUR ACCOUNT SECURITY REQUIRES IMMEDIATE ATTENTION

As a precautionary measure, we are requiring all users to reset their passwords. Your current password has been invalidated and you must set a new one to continue accessing your account.

WHAT HAPPENED:
{{breachDescription}}

WHAT YOU NEED TO DO:
1. Visit the link below to reset your password
2. Choose a strong, unique password you have not used before
3. Enable two-factor authentication if not already active
4. Review your recent account activity after logging in

Reset your password: {{resetUrl}}

This link will expire in {{expiryHours}} hours.
Your email: {{email}}
Incident reference: {{incidentRef}}

If you did not request this change, please contact our support team immediately.

(c) {{currentYear}} {{appName}}. All rights reserved.
This is an automated security notification.',
 'transactional', FALSE,
 '["displayName","email","resetUrl","breachDescription","expiryTime","expiryHours","incidentRef","appName","currentYear","supportUrl"]',
 TRUE),

-- 🔑 Mass Password Reset Template (non-breach, policy-driven)
('mass_password_reset', 'Mass Password Reset Notification',
 'Notification sent when admin triggers a policy-driven mass password reset',
 'Password Reset Required - {{appName}} Account',
 '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>Password Reset Required</title>
<!--[if mso]>
<noscript>
<xml>
<o:OfficeDocumentSettings>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
</noscript>
<![endif]-->
<style>
:root { color-scheme: light dark; supported-color-schemes: light dark; }
body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
img { border: 0; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
a { color: #2563eb; text-decoration: none; }
.container { max-width: 600px; margin: 0 auto; }
.header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding: 30px 40px; text-align: center; }
.header h1 { color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 24px; margin: 0; }
.content { background-color: #ffffff; padding: 40px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #1f2937; }
.info-box { background-color: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #2563eb; border-radius: 8px; padding: 20px; margin: 20px 0; }
.info-box h3 { color: #1e40af; margin: 0 0 10px 0; font-size: 18px; }
.btn { display: inline-block; background-color: #2563eb; color: #ffffff !important; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; text-decoration: none; margin: 20px 0; }
.btn:hover { background-color: #1d4ed8; }
.footer { background-color: #f9fafb; padding: 24px 40px; text-align: center; font-size: 12px; color: #9ca3af; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
@media (prefers-color-scheme: dark) { .content { background-color: #1f2937 !important; color: #e5e7eb !important; } .info-box { background-color: #1e3a5f !important; border-color: #1e40af !important; } .footer { background-color: #111827 !important; color: #6b7280 !important; } }
@media only screen and (max-width: 620px) { .container { width: 100% !important; } .content { padding: 24px !important; } .header { padding: 20px 24px !important; } }
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
<tr><td align="center" style="padding: 40px 20px;">
<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
<tr><td class="header">
<h1>&#x1F511; Password Reset Required</h1>
</td></tr>
<tr><td class="content">
<p>Dear {{displayName}},</p>
<div class="info-box">
<h3>&#x2139;&#xFE0F; Password reset required</h3>
<p style="margin: 0;">As part of our ongoing security practices, we are requiring all users to update their passwords. Please use the link below to set a new password.</p>
</div>
<p><strong>Reason:</strong> {{resetReason}}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center">
<a href="{{resetUrl}}" class="btn" style="color: #ffffff;">Reset Your Password</a>
</td></tr>
</table>
<p style="font-size: 14px; color: #6b7280;">This link will expire in {{expiryHours}} hours. If you need assistance, please contact our support team.</p>
<p style="font-size: 13px; color: #9ca3af; margin-top: 24px;">If the button above does not work, copy and paste this URL into your browser:<br><a href="{{resetUrl}}" style="color: #2563eb; word-break: break-all;">{{resetUrl}}</a></p>
</td></tr>
<tr><td class="footer">
<p>&copy; {{currentYear}} {{appName}}. All rights reserved.</p>
<p>This is an automated notification. Please do not reply to this email.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
 'Password Reset Required - {{appName}} Account

Dear {{displayName}},

PASSWORD RESET REQUIRED

As part of our ongoing security practices, we are requiring all users to update their passwords.

Reason: {{resetReason}}

Please visit the following link to reset your password:
{{resetUrl}}

This link will expire in {{expiryHours}} hours.

If you need assistance, please contact our support team.

(c) {{currentYear}} {{appName}}. All rights reserved.',
 'transactional', FALSE,
 '["displayName","resetUrl","resetReason","expiryHours","appName","currentYear"]',
 TRUE),

-- 🔔 Credential Reset Completed Template
('credential_reset_complete', 'Credential Reset Confirmation',
 'Confirmation sent to users after they successfully reset their password following a mass reset',
 'Your {{appName}} Password Has Been Successfully Updated',
 '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>Password Updated</title>
<style>
:root { color-scheme: light dark; supported-color-schemes: light dark; }
body { margin: 0; padding: 0; width: 100% !important; }
table { border-collapse: collapse; }
a { color: #2563eb; text-decoration: none; }
.container { max-width: 600px; margin: 0 auto; }
.header { background: linear-gradient(135deg, #059669 0%, #047857 100%); padding: 30px 40px; text-align: center; }
.header h1 { color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 24px; margin: 0; }
.content { background-color: #ffffff; padding: 40px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #1f2937; }
.success-box { background-color: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #059669; border-radius: 8px; padding: 20px; margin: 20px 0; }
.success-box h3 { color: #047857; margin: 0 0 10px 0; }
.footer { background-color: #f9fafb; padding: 24px 40px; text-align: center; font-size: 12px; color: #9ca3af; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
@media (prefers-color-scheme: dark) { .content { background-color: #1f2937 !important; color: #e5e7eb !important; } .success-box { background-color: #064e3b !important; border-color: #047857 !important; } }
@media only screen and (max-width: 620px) { .container { width: 100% !important; } .content { padding: 24px !important; } }
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
<tr><td align="center" style="padding: 40px 20px;">
<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
<tr><td class="header">
<h1>&#x2705; Password Updated Successfully</h1>
</td></tr>
<tr><td class="content">
<p>Dear {{displayName}},</p>
<div class="success-box">
<h3>Your password has been successfully updated</h3>
<p style="margin: 0;">Your account is now secured with your new password. You can continue using all {{appName}} services as normal.</p>
</div>
<p>If you did not make this change, please contact our support team immediately and consider enabling two-factor authentication for added security.</p>
</td></tr>
<tr><td class="footer">
<p>&copy; {{currentYear}} {{appName}}. All rights reserved.</p>
<p><a href="{{supportUrl}}" style="color: #6b7280;">Contact Support</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>',
 'Your {{appName}} Password Has Been Successfully Updated

Dear {{displayName}},

Your password has been successfully updated. Your account is now secured with your new password. You can continue using all {{appName}} services as normal.

If you did not make this change, please contact our support team immediately.

(c) {{currentYear}} {{appName}}. All rights reserved.',
 'transactional', FALSE,
 '["displayName","appName","currentYear","supportUrl"]',
 TRUE)
ON DUPLICATE KEY UPDATE updatedAt = NOW();

-- ✅ Record migration completion
-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)
