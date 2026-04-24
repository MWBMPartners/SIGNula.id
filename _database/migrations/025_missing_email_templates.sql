-- ============================================================================
-- 📧 SIGNula Database Migration: Missing Email Templates
-- ============================================================================
-- Version: 2.7.1
-- Date: 2026-04-22
-- Description: Add missing email templates for TODOs resolution
--
-- Templates Added:
-- - Organization invitation
-- - Account lockout notification
-- - Email address change verification
-- - Support ticket notifications
-- - Contact form submission notification
-- ============================================================================

USE `signula`;

-- ============================================================================
-- 📨 INSERT MISSING EMAIL TEMPLATES
-- ============================================================================

INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `category`, `requiredVariables`, `isActive`)
VALUES
-- Organization Invitation Template
('org_invitation', 'Organization Invitation',
'You''ve been invited to join {organizationName} on SIGNula',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, ''Helvetica Neue'', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: #fff; margin: 0; font-size: 28px;">SIGNula</h1>
    </div>
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <h2 style="color: #333; margin-top: 0;">You''ve Been Invited!</h2>
        <p>Hi there,</p>
        <p><strong>{inviterName}</strong> has invited you to join <strong>{organizationName}</strong> on SIGNula.</p>
        {#if inviterMessage}
        <div style="background: #fff; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0;">
            <p style="margin: 0; font-style: italic;">"{inviterMessage}"</p>
        </div>
        {/if}
        <p><strong>Your Role:</strong> {role}</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{acceptUrl}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">Accept Invitation</a>
        </div>
        <p style="color: #666; font-size: 14px;">This invitation will expire in {expiryDays} days.</p>
        <p style="color: #666; font-size: 14px;">If you don''t want to join this organization, you can safely ignore this email.</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'You''ve Been Invited to {organizationName}

Hi there,

{inviterName} has invited you to join {organizationName} on SIGNula.

{#if inviterMessage}
Message: "{inviterMessage}"
{/if}

Your Role: {role}

To accept this invitation, visit:
{acceptUrl}

This invitation will expire in {expiryDays} days.

If you don''t want to join this organization, you can safely ignore this email.

---
© 2026 SIGNula. All rights reserved.',
'organization', 'organizationName,inviterName,role,acceptUrl,expiryDays', TRUE),

-- Account Lockout Notification
('account_lockout', 'Account Lockout Notification',
'Security Alert: Your SIGNula account has been locked',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, ''Helvetica Neue'', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: #fff; margin: 0; font-size: 28px;">🔒 Security Alert</h1>
    </div>
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <h2 style="color: #dc3545; margin-top: 0;">Account Locked</h2>
        <p>Hi {displayName},</p>
        <p>Your SIGNula account has been temporarily locked due to multiple failed login attempts.</p>
        <div style="background: #fff; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Email:</strong> {email}</p>
            <p style="margin: 5px 0 0 0;"><strong>Locked Until:</strong> {lockedUntil}</p>
            <p style="margin: 5px 0 0 0;"><strong>IP Address:</strong> {ipAddress}</p>
        </div>
        <p><strong>What happened?</strong></p>
        <p>We detected {failedAttempts} failed login attempts to your account. As a security measure, we''ve temporarily locked your account.</p>
        <p><strong>What should you do?</strong></p>
        <ul>
            <li>Wait {lockoutMinutes} minutes for automatic unlock, or</li>
            <li>Reset your password immediately using the link below:</li>
        </ul>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{resetPasswordUrl}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">Reset Password</a>
        </div>
        <p style="color: #666; font-size: 14px;"><strong>Wasn''t you?</strong> If you did not attempt to log in, your account may be compromised. Please reset your password immediately and enable multi-factor authentication.</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'Security Alert: Account Locked

Hi {displayName},

Your SIGNula account has been temporarily locked due to multiple failed login attempts.

Details:
- Email: {email}
- Locked Until: {lockedUntil}
- IP Address: {ipAddress}

What happened?
We detected {failedAttempts} failed login attempts to your account. As a security measure, we''ve temporarily locked your account.

What should you do?
1. Wait {lockoutMinutes} minutes for automatic unlock, or
2. Reset your password immediately: {resetPasswordUrl}

Wasn''t you? If you did not attempt to log in, your account may be compromised. Please reset your password immediately and enable multi-factor authentication.

---
© 2026 SIGNula. All rights reserved.',
'security', 'displayName,email,failedAttempts,lockedUntil,lockoutMinutes,ipAddress,resetPasswordUrl', TRUE),

-- Email Address Change Verification
('email_change_verification', 'Email Address Change Verification',
'Verify your new email address for SIGNula',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, ''Helvetica Neue'', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: #fff; margin: 0; font-size: 28px;">SIGNula</h1>
    </div>
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <h2 style="color: #333; margin-top: 0;">Verify Your New Email</h2>
        <p>Hi {displayName},</p>
        <p>You recently changed your email address from <strong>{oldEmail}</strong> to <strong>{newEmail}</strong>.</p>
        <p>Please verify your new email address by clicking the button below:</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{verificationUrl}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">Verify Email Address</a>
        </div>
        <p>Or use this verification code: <strong style="font-size: 24px; letter-spacing: 2px; color: #667eea;">{verificationCode}</strong></p>
        <p style="color: #666; font-size: 14px;">This verification link will expire in {expiryMinutes}.</p>
        <p style="color: #dc3545; font-size: 14px;"><strong>Didn''t change your email?</strong> If you did not request this change, please contact support immediately as your account may be compromised.</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'Verify Your New Email Address

Hi {displayName},

You recently changed your email address from {oldEmail} to {newEmail}.

Please verify your new email address by visiting:
{verificationUrl}

Or use this verification code: {verificationCode}

This verification link will expire in {expiryMinutes}.

Didn''t change your email? If you did not request this change, please contact support immediately as your account may be compromised.

---
© 2026 SIGNula. All rights reserved.',
'account', 'displayName,oldEmail,newEmail,verificationUrl,verificationCode,expiryMinutes', TRUE),

-- Support Ticket Created (User Notification)
('support_ticket_user', 'Support Ticket Created',
'Support Ticket #{ticketNumber} Created',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, ''Helvetica Neue'', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: #fff; margin: 0; font-size: 28px;">SIGNula Support</h1>
    </div>
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <h2 style="color: #333; margin-top: 0;">Ticket #{ticketNumber} Created</h2>
        <p>Hi {displayName},</p>
        <p>Your support ticket has been created successfully. Our team will review it and respond as soon as possible.</p>
        <div style="background: #fff; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Ticket Number:</strong> #{ticketNumber}</p>
            <p style="margin: 5px 0 0 0;"><strong>Subject:</strong> {subject}</p>
            <p style="margin: 5px 0 0 0;"><strong>Priority:</strong> {priority}</p>
            <p style="margin: 5px 0 0 0;"><strong>Category:</strong> {category}</p>
        </div>
        <p><strong>Your Message:</strong></p>
        <div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px;">
            <p style="margin: 0; white-space: pre-wrap;">{message}</p>
        </div>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{ticketUrl}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">View Ticket</a>
        </div>
        <p style="color: #666; font-size: 14px;">You will receive an email notification when our support team responds.</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'Support Ticket Created

Hi {displayName},

Your support ticket has been created successfully. Our team will review it and respond as soon as possible.

Ticket Details:
- Ticket Number: #{ticketNumber}
- Subject: {subject}
- Priority: {priority}
- Category: {category}

Your Message:
{message}

View your ticket: {ticketUrl}

You will receive an email notification when our support team responds.

---
© 2026 SIGNula. All rights reserved.',
'support', 'displayName,ticketNumber,subject,priority,category,message,ticketUrl', TRUE),

-- Support Ticket Created (Team Notification)
('support_ticket_team', 'New Support Ticket',
'New Support Ticket #{ticketNumber} - {subject}',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, ''Helvetica Neue'', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: #fff; margin: 0; font-size: 28px;">🎫 New Support Ticket</h1>
    </div>
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <h2 style="color: #333; margin-top: 0;">Ticket #{ticketNumber}</h2>
        <div style="background: #fff; border-left: 4px solid #f5576c; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>From:</strong> {displayName} ({email})</p>
            <p style="margin: 5px 0 0 0;"><strong>Subject:</strong> {subject}</p>
            <p style="margin: 5px 0 0 0;"><strong>Priority:</strong> <span style="color: {priorityColor};">{priority}</span></p>
            <p style="margin: 5px 0 0 0;"><strong>Category:</strong> {category}</p>
        </div>
        <p><strong>Message:</strong></p>
        <div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px;">
            <p style="margin: 0; white-space: pre-wrap;">{message}</p>
        </div>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{ticketUrl}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">View & Respond</a>
        </div>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'New Support Ticket #{ticketNumber}

From: {displayName} ({email})
Subject: {subject}
Priority: {priority}
Category: {category}

Message:
{message}

View and respond: {ticketUrl}

---
© 2026 SIGNula. All rights reserved.',
'support', 'displayName,email,ticketNumber,subject,priority,priorityColor,category,message,ticketUrl', TRUE),

-- Contact Form Submission
('contact_form_submission', 'New Contact Form Submission',
'New Contact Form Message from {name}',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, ''Helvetica Neue'', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: #fff; margin: 0; font-size: 28px;">📬 Contact Form</h1>
    </div>
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <h2 style="color: #333; margin-top: 0;">New Message Received</h2>
        <div style="background: #fff; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Name:</strong> {name}</p>
            <p style="margin: 5px 0 0 0;"><strong>Email:</strong> <a href="mailto:{email}">{email}</a></p>
            {#if phone}
            <p style="margin: 5px 0 0 0;"><strong>Phone:</strong> {phone}</p>
            {/if}
            {#if company}
            <p style="margin: 5px 0 0 0;"><strong>Company:</strong> {company}</p>
            {/if}
            <p style="margin: 5px 0 0 0;"><strong>Subject:</strong> {subject}</p>
        </div>
        <p><strong>Message:</strong></p>
        <div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px;">
            <p style="margin: 0; white-space: pre-wrap;">{message}</p>
        </div>
        <p style="color: #666; font-size: 14px;"><strong>Submitted:</strong> {submittedAt}</p>
        <p style="color: #666; font-size: 14px;"><strong>IP Address:</strong> {ipAddress}</p>
        <p style="color: #666; font-size: 14px;"><strong>User Agent:</strong> {userAgent}</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'New Contact Form Message

Name: {name}
Email: {email}
{#if phone}Phone: {phone}{/if}
{#if company}Company: {company}{/if}
Subject: {subject}

Message:
{message}

Submitted: {submittedAt}
IP Address: {ipAddress}
User Agent: {userAgent}

---
© 2026 SIGNula. All rights reserved.',
'contact', 'name,email,subject,message,submittedAt,ipAddress,userAgent', TRUE);

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
