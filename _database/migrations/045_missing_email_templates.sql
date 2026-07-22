-- ============================================================================
-- SIGNula Database Migration 045
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- 📋 Migration: Recreate Missing (Non-Organization) Email Templates
-- 📅 Date:      2026-07-22
-- 🔖 Version:   2.8.0
-- 📝 Description:
--   During the universal-login/autopilot branch merge, the universal-login
--   branch's `025_missing_email_templates.sql` was DROPPED in favour of
--   autopilot's `028_organizations.sql`, which already seeds the canonical
--   `org_invitation` (and `org_welcome`) templates. However, dropping 025
--   ALSO dropped its other FIVE templates, which are NOT recreated anywhere
--   else and ARE referenced by live application code:
--
--     - account_lockout              — Auth.php account-lockout notice
--     - email_change_verification    — settings/profile.php email-change flow
--     - support_ticket_user           — SIGNula.id/support/ticket.php (submitter copy)
--     - support_ticket_team           — SIGNula.id/support/ticket.php (team copy)
--     - contact_form_submission      — SIGNula.com/contact.php
--
--   This migration recreates those 5 templates (deliberately NOT
--   org_invitation — 028 already owns that key and is canonical).
--
--   🔧 SYNTAX FIX: the dropped 025 file authored every placeholder using
--   single-curly-brace notation (one open/close brace around each variable
--   name), and wrapped a few optional fields in Handlebars-style
--   open/close "if" conditional blocks (hash-if ... slash-if). Neither of
--   those is understood by the actual renderer —
--   EmailService::replaceVariables() (web/private_html/email/EmailService.php)
--   ONLY performs a literal str_replace() of DOUBLE-curly-brace tokens
--   (two open braces, name, two close braces). Left as-is, every
--   single-brace placeholder and every hash-if/slash-if marker would have
--   been emailed to users verbatim instead of being substituted/removed.
--   This migration:
--     1. Rewrites every placeholder to double-curly-brace form.
--     2. Flattens every hash-if/slash-if block — the conditional markers are
--        deleted and the inner content is kept as always-included plain text
--        (the sensible default wording), since the renderer has no
--        conditional-block support to preserve.
--
--   Column list and INSERT style mirror 028_organizations.sql's
--   `tblEmailTemplates` seed EXACTLY (templateKey, templateName, subject,
--   bodyHTML, bodyText, variables, description, locale) with the same
--   `ON DUPLICATE KEY UPDATE` idempotency pattern keyed on the UNIQUE
--   `templateKey` column, so re-running this migration on an
--   already-migrated database is safe.
--
-- 🔗 Related: web/private_html/email/EmailService.php (replaceVariables() —
--    the {{}}-only substitution contract this migration's templates now
--    honour), _database/migrations/028_organizations.sql (the canonical
--    org_invitation/org_welcome seed this migration deliberately does NOT
--    duplicate).
-- ============================================================================

-- ============================================================================
-- 📧 RECREATE 5 NON-ORGANIZATION EMAIL TEMPLATES (canonical tblEmailTemplates columns)
-- ============================================================================
INSERT INTO `tblEmailTemplates` (`templateKey`, `templateName`, `subject`, `bodyHTML`, `bodyText`, `variables`, `description`, `locale`) VALUES

-- ----------------------------------------------------------------------------
-- 🔒 Account Lockout Notification
-- ----------------------------------------------------------------------------
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
        <p>Hi {{displayName}},</p>
        <p>Your SIGNula account has been temporarily locked due to multiple failed login attempts.</p>
        <div style="background: #fff; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Email:</strong> {{email}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Locked Until:</strong> {{lockedUntil}}</p>
            <p style="margin: 5px 0 0 0;"><strong>IP Address:</strong> {{ipAddress}}</p>
        </div>
        <p><strong>What happened?</strong></p>
        <p>We detected {{failedAttempts}} failed login attempts to your account. As a security measure, we''ve temporarily locked your account.</p>
        <p><strong>What should you do?</strong></p>
        <ul>
            <li>Wait {{lockoutMinutes}} minutes for automatic unlock, or</li>
            <li>Reset your password immediately using the link below:</li>
        </ul>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{resetPasswordUrl}}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">Reset Password</a>
        </div>
        <p style="color: #666; font-size: 14px;"><strong>Wasn''t you?</strong> If you did not attempt to log in, your account may be compromised. Please reset your password immediately and enable multi-factor authentication.</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'Security Alert: Account Locked

Hi {{displayName}},

Your SIGNula account has been temporarily locked due to multiple failed login attempts.

Details:
- Email: {{email}}
- Locked Until: {{lockedUntil}}
- IP Address: {{ipAddress}}

What happened?
We detected {{failedAttempts}} failed login attempts to your account. As a security measure, we''ve temporarily locked your account.

What should you do?
1. Wait {{lockoutMinutes}} minutes for automatic unlock, or
2. Reset your password immediately: {{resetPasswordUrl}}

Wasn''t you? If you did not attempt to log in, your account may be compromised. Please reset your password immediately and enable multi-factor authentication.

---
© 2026 SIGNula. All rights reserved.',
'["displayName","email","failedAttempts","lockedUntil","lockoutMinutes","ipAddress","resetPasswordUrl"]',
'Account lockout security notification email', 'en_US'),

-- ----------------------------------------------------------------------------
-- ✉️ Email Address Change Verification
-- ----------------------------------------------------------------------------
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
        <p>Hi {{displayName}},</p>
        <p>You recently changed your email address from <strong>{{oldEmail}}</strong> to <strong>{{newEmail}}</strong>.</p>
        <p>Please verify your new email address by clicking the button below:</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{verificationUrl}}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">Verify Email Address</a>
        </div>
        <p>Or use this verification code: <strong style="font-size: 24px; letter-spacing: 2px; color: #667eea;">{{verificationCode}}</strong></p>
        <p style="color: #666; font-size: 14px;">This verification link will expire in {{expiryMinutes}}.</p>
        <p style="color: #dc3545; font-size: 14px;"><strong>Didn''t change your email?</strong> If you did not request this change, please contact support immediately as your account may be compromised.</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'Verify Your New Email Address

Hi {{displayName}},

You recently changed your email address from {{oldEmail}} to {{newEmail}}.

Please verify your new email address by visiting:
{{verificationUrl}}

Or use this verification code: {{verificationCode}}

This verification link will expire in {{expiryMinutes}}.

Didn''t change your email? If you did not request this change, please contact support immediately as your account may be compromised.

---
© 2026 SIGNula. All rights reserved.',
'["displayName","oldEmail","newEmail","verificationUrl","verificationCode","expiryMinutes"]',
'Email address change verification email', 'en_US'),

-- ----------------------------------------------------------------------------
-- 🎫 Support Ticket Created (User Notification)
-- ----------------------------------------------------------------------------
('support_ticket_user', 'Support Ticket Created',
'Support Ticket #{{ticketNumber}} Created',
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
        <h2 style="color: #333; margin-top: 0;">Ticket #{{ticketNumber}} Created</h2>
        <p>Hi {{displayName}},</p>
        <p>Your support ticket has been created successfully. Our team will review it and respond as soon as possible.</p>
        <div style="background: #fff; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Ticket Number:</strong> #{{ticketNumber}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Subject:</strong> {{subject}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Priority:</strong> {{priority}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Category:</strong> {{category}}</p>
        </div>
        <p><strong>Your Message:</strong></p>
        <div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px;">
            <p style="margin: 0; white-space: pre-wrap;">{{message}}</p>
        </div>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ticketUrl}}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">View Ticket</a>
        </div>
        <p style="color: #666; font-size: 14px;">You will receive an email notification when our support team responds.</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'Support Ticket Created

Hi {{displayName}},

Your support ticket has been created successfully. Our team will review it and respond as soon as possible.

Ticket Details:
- Ticket Number: #{{ticketNumber}}
- Subject: {{subject}}
- Priority: {{priority}}
- Category: {{category}}

Your Message:
{{message}}

View your ticket: {{ticketUrl}}

You will receive an email notification when our support team responds.

---
© 2026 SIGNula. All rights reserved.',
'["displayName","ticketNumber","subject","priority","category","message","ticketUrl"]',
'Support ticket created — notification sent to the submitting user', 'en_US'),

-- ----------------------------------------------------------------------------
-- 🎫 Support Ticket Created (Team Notification)
-- ----------------------------------------------------------------------------
('support_ticket_team', 'New Support Ticket',
'New Support Ticket #{{ticketNumber}} - {{subject}}',
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
        <h2 style="color: #333; margin-top: 0;">Ticket #{{ticketNumber}}</h2>
        <div style="background: #fff; border-left: 4px solid #f5576c; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>From:</strong> {{displayName}} ({{email}})</p>
            <p style="margin: 5px 0 0 0;"><strong>Subject:</strong> {{subject}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Priority:</strong> <span style="color: {{priorityColor}};">{{priority}}</span></p>
            <p style="margin: 5px 0 0 0;"><strong>Category:</strong> {{category}}</p>
        </div>
        <p><strong>Message:</strong></p>
        <div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px;">
            <p style="margin: 0; white-space: pre-wrap;">{{message}}</p>
        </div>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ticketUrl}}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; padding: 12px 30px; border-radius: 5px; display: inline-block; font-weight: bold;">View & Respond</a>
        </div>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'New Support Ticket #{{ticketNumber}}

From: {{displayName}} ({{email}})
Subject: {{subject}}
Priority: {{priority}}
Category: {{category}}

Message:
{{message}}

View and respond: {{ticketUrl}}

---
© 2026 SIGNula. All rights reserved.',
'["displayName","email","ticketNumber","subject","priority","priorityColor","category","message","ticketUrl"]',
'Support ticket created — notification sent to the support team', 'en_US'),

-- ----------------------------------------------------------------------------
-- 📬 Contact Form Submission
-- ----------------------------------------------------------------------------
-- 🔧 Flattened conditionals: the dropped 025 draft wrapped `phone` and
--    `company` in Handlebars-style hash-if/slash-if conditional blocks.
--    EmailService::replaceVariables() has no conditional-block support, so
--    those markers would otherwise be emailed verbatim. Both fields are now
--    ALWAYS rendered as plain rows (callers should pass an empty string when
--    a submitter did not supply a phone/company value).
('contact_form_submission', 'New Contact Form Submission',
'New Contact Form Message from {{name}}',
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
            <p style="margin: 0;"><strong>Name:</strong> {{name}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Email:</strong> <a href="mailto:{{email}}">{{email}}</a></p>
            <p style="margin: 5px 0 0 0;"><strong>Phone:</strong> {{phone}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Company:</strong> {{company}}</p>
            <p style="margin: 5px 0 0 0;"><strong>Subject:</strong> {{subject}}</p>
        </div>
        <p><strong>Message:</strong></p>
        <div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px;">
            <p style="margin: 0; white-space: pre-wrap;">{{message}}</p>
        </div>
        <p style="color: #666; font-size: 14px;"><strong>Submitted:</strong> {{submittedAt}}</p>
        <p style="color: #666; font-size: 14px;"><strong>IP Address:</strong> {{ipAddress}}</p>
        <p style="color: #666; font-size: 14px;"><strong>User Agent:</strong> {{userAgent}}</p>
    </div>
    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        <p>© 2026 SIGNula. All rights reserved.</p>
    </div>
</body>
</html>',
'New Contact Form Message

Name: {{name}}
Email: {{email}}
Phone: {{phone}}
Company: {{company}}
Subject: {{subject}}

Message:
{{message}}

Submitted: {{submittedAt}}
IP Address: {{ipAddress}}
User Agent: {{userAgent}}

---
© 2026 SIGNula. All rights reserved.',
'["name","email","phone","company","subject","message","submittedAt","ipAddress","userAgent"]',
'Contact form submission notification email', 'en_US')

ON DUPLICATE KEY UPDATE
    `subject`    = VALUES(`subject`),
    `bodyHTML`   = VALUES(`bodyHTML`),
    `bodyText`   = VALUES(`bodyText`),
    `variables`  = VALUES(`variables`);

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
SELECT 'Migration 045_missing_email_templates.sql completed successfully' AS Result;
