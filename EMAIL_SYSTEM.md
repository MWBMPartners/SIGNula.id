# 📧 SIGNula Email System - Complete Documentation

**Version:** 2.0.0
**Last Updated:** 2026-02-02
**Status:** Production Ready

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Email Providers](#email-providers)
4. [Queue System](#queue-system)
5. [Template System](#template-system)
6. [Tracking & Analytics](#tracking--analytics)
7. [Setup & Configuration](#setup--configuration)
8. [Administration](#administration)
9. [API Integration](#api-integration)
10. [Monitoring & Health Checks](#monitoring--health-checks)
11. [Troubleshooting](#troubleshooting)
12. [Best Practices](#best-practices)

---

## 🎯 Overview

The SIGNula Email System is an enterprise-grade email delivery platform supporting multiple email providers with automatic fallback, tracking, analytics, and comprehensive management tools.

### **Key Features**

- ✅ **7 Email Providers** - Microsoft Graph, Gmail API, SendGrid, Mailgun, Amazon SES, Postmark, SMTP
- ✅ **Automatic Fallback** - If primary provider fails, automatically tries alternates
- ✅ **Email Tracking** - Open and click tracking with detailed analytics
- ✅ **Template System** - Reusable templates with variable support and preview
- ✅ **Queue Management** - Priority-based queue with retry logic
- ✅ **Health Monitoring** - Automatic provider health checks
- ✅ **Unsubscribe Management** - Built-in unsubscribe handling
- ✅ **Campaign Analytics** - Track performance across campaigns
- ✅ **Bounce Handling** - Automatic bounce detection and processing

---

## 🏗️ Architecture

### **System Components**

```
┌─────────────────────────────────────────────────────────┐
│                    Application Layer                     │
│  (EmailService, EmailTemplateManager, EmailTracker)     │
└───────────────────┬─────────────────────────────────────┘
                    │
┌───────────────────▼─────────────────────────────────────┐
│                  Email Queue Processor                   │
│         (Batch processing, Retry logic, Routing)         │
└───────────────────┬─────────────────────────────────────┘
                    │
        ┌───────────┼───────────┐
        │           │           │
┌───────▼───┐  ┌───▼───┐  ┌───▼────┐
│ Microsoft │  │SendGrid│  │ SMTP   │
│  Graph    │  │        │  │Fallback│
└───────────┘  └────────┘  └────────┘
```

### **Database Tables**

- **tblEmailQueue** - Email queue with tracking data
- **tblEmailTemplates** - Reusable email templates
- **tblEmailTrackingEvents** - Open/click/bounce events
- **tblEmailUnsubscribes** - Unsubscribe list
- **tblEmailProviderHealth** - Provider health status
- **tblEmailCampaigns** - Campaign management (optional)

---

## 📧 Email Providers

### **Available Providers**

| Provider | Type | Best For | Cost | Setup Difficulty |
|----------|------|----------|------|------------------|
| **Microsoft Graph** | API | Microsoft 365 users | Included | Medium |
| **Gmail API** | API | Google Workspace users | Included | Medium |
| **SendGrid** | API | All users | Free tier available | Easy |
| **Mailgun** | API | Developers | Free tier available | Easy |
| **Amazon SES** | API | AWS users | Very cheap | Medium |
| **Postmark** | API | Transactional emails | Paid | Easy |
| **SMTP** | Protocol | Universal fallback | Varies | Easy |

### **Provider Priority**

The system tries providers in this order (configurable):
1. Preferred provider (set in settings)
2. Other configured providers (in order)
3. SMTP fallback

### **Configuration**

See [Setup & Configuration](#setup--configuration) for detailed setup instructions for each provider.

---

## 📬 Queue System

### **How It Works**

1. **Email is queued** using `EmailService::queueEmail()`
2. **Queue processor runs** (via cron or manual trigger)
3. **Emails are sent** using configured providers
4. **Retry on failure** with exponential backoff (5min → 15min → 30min)
5. **Dead letter queue** for permanent failures

### **Priority Levels**

- **1** = Highest priority (send immediately)
- **5** = Normal priority (default)
- **10** = Lowest priority (batch send)

### **Queue Email Example**

```php
EmailService::queueEmail(
    recipientEmail: 'user@example.com',
    subject: 'Welcome to SIGNula!',
    bodyHTML: '<h1>Welcome!</h1><p>Thanks for joining...</p>',
    bodyText: 'Welcome! Thanks for joining...',
    priority: 1,  // High priority
    cc: ['admin@example.com'],
    bcc: ['archive@example.com']
);
```

### **Processing Queue**

```bash
# Via CLI (recommended for cron)
php /path/to/EmailQueueProcessor.php -v --limit=50

# Via PHP
$stats = EmailService::processQueue(50, verbose: true);
```

### **Cron Job Setup**

```bash
# Process email queue every 5 minutes
*/5 * * * * php /path/to/EmailQueueProcessor.php -v >> /var/log/email-queue.log 2>&1
```

---

## 📝 Template System

### **Creating Templates**

Templates use `{{variable}}` syntax for dynamic content.

```php
$templateID = EmailTemplateManager::createTemplate([
    'templateKey' => 'welcome_email',
    'templateName' => 'Welcome Email',
    'subject' => 'Welcome to {{companyName}}',
    'bodyHTML' => '<h1>Welcome {{displayName}}!</h1>...',
    'bodyText' => 'Welcome {{displayName}}!...',
    'category' => 'transactional',
    'trackingEnabled' => true
], $userID);
```

### **Using Templates**

```php
EmailService::sendTemplateEmail(
    recipientEmail: 'user@example.com',
    templateKey: 'welcome_email',
    variables: [
        'displayName' => 'John Doe',
        'companyName' => 'SIGNula'
    ]
);
```

### **Template Preview**

```php
$preview = EmailTemplateManager::previewTemplate($templateID, [
    'displayName' => 'Sample User'
]);

// Returns: subject, bodyHTML, bodyText with variables replaced
```

---

## 📊 Tracking & Analytics

### **Open Tracking**

Emails include a 1x1 transparent pixel that tracks when emails are opened.

**Endpoint:** `/email/track-open?t={trackingToken}`

### **Click Tracking**

Links are rewritten to track clicks before redirecting to destination.

**Endpoint:** `/email/track-click?t={trackingToken}&url={destination}`

### **Enabling Tracking**

```php
// Enable tracking per template
EmailTemplateManager::updateTemplate($templateID, [
    'trackingEnabled' => true
]);

// OR enable per email
EmailService::queueEmail(
    // ... email params
    trackingEnabled: true  // Custom parameter
);
```

### **Analytics**

```php
// Get email statistics
$stats = EmailTracker::getEmailStats($emailID);
// Returns: opens, clicks, opened_at, events

// Get campaign statistics
$campaignStats = EmailTracker::getCampaignStats(
    templateID: $templateID,
    startDate: new DateTime('2026-01-01'),
    endDate: new DateTime('2026-01-31')
);
// Returns: total_sent, unique_opens, unique_clicks, open_rate, click_rate
```

### **Unsubscribe Management**

```php
// Check if email is unsubscribed
if (EmailTracker::isUnsubscribed('user@example.com')) {
    // Don't send email
}

// Add to unsubscribe list
EmailTracker::addToUnsubscribeList(
    email: 'user@example.com',
    reason: 'user_request',
    category: 'marketing'  // null = all emails
);
```

---

## ⚙️ Setup & Configuration

### **1. Database Setup**

```sql
-- Run migration script
SOURCE _database/migrations/001_email_system_upgrade.sql
```

### **2. Configure Provider**

#### **Option A: Microsoft Graph (Microsoft 365)**

```php
setSetting('email.microsoft.tenant_id', 'YOUR_TENANT_ID');
setSetting('email.microsoft.client_id', 'YOUR_CLIENT_ID');
setSetting('email.microsoft.client_secret', SecurityUtils::encrypt('YOUR_SECRET'));
setSetting('email.microsoft.from_email', 'noreply@yourdomain.com');
setSetting('email.provider', 'microsoft_graph');
```

See [MicrosoftGraphEmailProvider.php](_includes/email/providers/MicrosoftGraphEmailProvider.php) for full setup instructions.

#### **Option B: SendGrid (Easy Setup)**

```php
setSetting('email.sendgrid.api_key', SecurityUtils::encrypt('YOUR_API_KEY'));
setSetting('email.sendgrid.from_email', 'noreply@yourdomain.com');
setSetting('email.provider', 'sendgrid');
```

#### **Option C: SMTP (Universal)**

```php
setSetting('email.smtp.host', 'smtp.gmail.com');
setSetting('email.smtp.port', '587');
setSetting('email.smtp.encryption', 'tls');
setSetting('email.smtp.username', 'your-email@gmail.com');
setSetting('email.smtp.password', SecurityUtils::encrypt('YOUR_PASSWORD'));
setSetting('email.smtp.from_email', 'your-email@gmail.com');
setSetting('email.provider', 'smtp');
```

### **3. Test Configuration**

```php
// Send test email
EmailService::queueEmail(
    'admin@yourdomain.com',
    'Test Email',
    '<p>Test email from SIGNula</p>',
    'Test email from SIGNula',
    priority: 1
);

// Process queue
EmailService::processQueue(1);
```

---

## 👨‍💼 Administration

### **Admin Dashboard**

Access: `/admin/email-dashboard`

Features:
- Real-time queue statistics
- Provider health status
- Recent emails with engagement metrics
- Quick actions (process queue, create template)
- Visual analytics with Chart.js

### **Provider Configuration**

Access: `/admin/email-config`

Features:
- Configure all 7 providers
- Test email sending
- View setup instructions
- Manage queue
- Provider status indicators

### **Health Monitoring**

```bash
# Check all providers
php EmailProviderHealthMonitor.php -v

# Check specific provider
php EmailProviderHealthMonitor.php --provider=sendgrid -v
```

### **Queue Management**

```php
// Get queue statistics
$stats = EmailService::getQueueStats();
// Returns: total, pending, sent, failed, oldest_pending, last_sent

// Cleanup old emails (30 days)
$deleted = EmailService::cleanupOldEmails(30);
```

---

## 🔌 API Integration

### **Sending Emails Programmatically**

```php
// Simple email
EmailService::queueEmail(
    recipientEmail: 'user@example.com',
    subject: 'Hello',
    bodyHTML: '<p>Hello World</p>',
    bodyText: 'Hello World'
);

// Email with all options
EmailService::queueEmail(
    recipientEmail: 'user@example.com',
    subject: 'Welcome!',
    bodyHTML: '<h1>Welcome!</h1>',
    bodyText: 'Welcome!',
    fromEmail: 'noreply@example.com',
    fromName: 'SIGNula',
    replyTo: 'support@example.com',
    userID: 123,
    templateID: null,
    priority: 5,
    scheduledFor: new DateTime('+1 hour'),
    cc: ['admin@example.com'],
    bcc: ['archive@example.com'],
    attachments: [
        [
            'name' => 'document.pdf',
            'content' => file_get_contents('document.pdf'),
            'type' => 'application/pdf'
        ]
    ]
);
```

### **Using Templates**

```php
// Send using template
EmailService::sendTemplateEmail(
    recipientEmail: 'user@example.com',
    templateKey: 'welcome_email',
    variables: [
        'displayName' => 'John Doe',
        'verificationUrl' => 'https://...',
        'expiryMinutes' => '30'
    ],
    userID: 123,
    priority: 1
);
```

---

## 🏥 Monitoring & Health Checks

### **Provider Health**

The system automatically tracks provider health:
- Success/failure rates
- Average response times
- Last error details
- Last success timestamp

### **Automated Monitoring**

```bash
# Add to crontab - check every 15 minutes
*/15 * * * * php EmailProviderHealthMonitor.php >> /var/log/provider-health.log 2>&1
```

### **Alerts**

```php
// Check for alerts
$alerts = EmailProviderHealthMonitor::checkForAlerts();

foreach ($alerts as $alert) {
    // Alert types: error, warning
    // alert['severity'], alert['provider'], alert['message']
}
```

### **Health Criteria**

- **Healthy**: Success rate > 90%, responding quickly
- **Degraded**: Success rate 70-90%, slow responses
- **Unhealthy**: Success rate < 70%, errors, or not responding

---

## 🔧 Troubleshooting

### **Common Issues**

#### **Emails Not Sending**

1. Check queue status: `SELECT * FROM tblEmailQueue WHERE status = 'failed'`
2. Check provider configuration: `/admin/email-config`
3. Check provider health: `php EmailProviderHealthMonitor.php -v`
4. Review error logs: `tblEmailQueue.lastError` and `tblErrorLog`

#### **Low Deliverability**

1. Verify sender domain (SPF, DKIM, DMARC)
2. Check provider reputation
3. Review bounce rates
4. Ensure proper email authentication

#### **Tracking Not Working**

1. Verify tracking is enabled on template
2. Check tracking URLs are accessible
3. Review tracking events: `SELECT * FROM tblEmailTrackingEvents`

### **Debugging**

```bash
# Verbose queue processing
php EmailQueueProcessor.php -v --limit=1

# Check specific email
SELECT * FROM tblEmailQueue WHERE emailID = 123;

# View tracking events
SELECT * FROM tblEmailTrackingEvents WHERE emailID = 123;
```

---

## ✅ Best Practices

### **Provider Selection**

1. **Use corporate providers first** (Microsoft Graph, Gmail API) for best deliverability
2. **Configure multiple providers** for redundancy
3. **Use SMTP as final fallback**
4. **Monitor provider health** regularly

### **Email Sending**

1. **Always provide plain text alternative** to HTML
2. **Use templates** for consistent branding
3. **Set appropriate priority** (don't abuse high priority)
4. **Include unsubscribe links** in marketing emails
5. **Validate email addresses** before queuing

### **Template Design**

1. **Keep templates focused** - one purpose per template
2. **Use descriptive variable names** (e.g., `{{displayName}}`, not `{{n}}`)
3. **Test templates** with preview before use
4. **Version templates** when making significant changes

### **Queue Management**

1. **Process queue regularly** (every 5 minutes recommended)
2. **Monitor queue size** - alert if pending count is high
3. **Clean up old emails** monthly
4. **Review failed emails** and fix issues

### **Performance**

1. **Batch process** emails (50-100 at a time)
2. **Use cron jobs** instead of real-time processing
3. **Index database tables** (already done in schema)
4. **Archive old tracking events** annually

### **Security**

1. **Encrypt all credentials** using `SecurityUtils::encrypt()`
2. **Use environment variables** for sensitive data
3. **Implement rate limiting** per user/IP
4. **Validate all input** before queuing emails
5. **Monitor for spam patterns**

---

## 📞 Support & Resources

### **Setup Guides by Provider**

- [Microsoft Graph Setup](https://docs.microsoft.com/en-us/graph/api/user-sendmail)
- [Gmail API Setup](https://developers.google.com/gmail/api/guides/sending)
- [SendGrid Setup](https://docs.sendgrid.com/for-developers)
- [Mailgun Setup](https://documentation.mailgun.com)
- [Amazon SES Setup](https://docs.aws.amazon.com/ses/)
- [Postmark Setup](https://postmarkapp.com/developer)

### **File Reference**

- **Core:** `EmailService.php`, `EmailQueueProcessor.php`
- **Providers:** `_includes/email/providers/`
- **Templates:** `EmailTemplateManager.php`
- **Tracking:** `EmailTracker.php`
- **Monitoring:** `EmailProviderHealthMonitor.php`
- **Admin UI:** `public_html/admin/email-dashboard.php`

### **Database Schema**

- **Full Schema:** `_database/email_schema.sql`
- **Migration:** `_database/migrations/001_email_system_upgrade.sql`

---

**Version:** 2.0.0
**Last Updated:** 2026-02-02
**License:** Proprietary - SIGNula Project
**Maintainer:** MWBM Partners Ltd
