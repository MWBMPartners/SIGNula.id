# 🚀 SIGNula Email System - Advanced Features

**Version:** 3.0.0
**Last Updated:** 2026-02-02
**Status:** Production Ready

---

## 📋 Overview

This document covers the advanced email features added to the SIGNula email system. These features extend the core email functionality documented in [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md).

---

## 🆕 New Features Summary

### ✅ Implemented Features

1. **🔗 Webhook Handler System** - Process callbacks from email providers
2. **🧪 A/B Testing Framework** - Multi-variant email testing with statistical analysis
3. **💧 Drip Campaign Automation** - Automated email sequences
4. **🎨 Advanced Personalization** - Dynamic content based on user data and behavior
5. **⏰ Enhanced Scheduling** - Timezone-aware scheduling with optimal send times
6. **📊 Webhook Management UI** - Admin interface for webhook configuration

---

## 🔗 1. Webhook Handler System

### Purpose
Receive and process real-time callbacks from email providers for delivery events (delivered, bounced, opened, clicked, etc.).

### Features
- **Multi-provider Support**: SendGrid, Mailgun, Postmark, Amazon SES
- **Signature Verification**: Validates webhook authenticity
- **Event Normalization**: Standardizes events across providers
- **Automatic Updates**: Updates email queue status automatically
- **Security**: Validates signatures before processing

### Files
- [_includes/email/EmailWebhookHandler.php](_includes/email/EmailWebhookHandler.php)
- [public_html/webhooks/email.php](public_html/webhooks/email.php)

### Setup

Configure webhook URLs in your email provider's dashboard:

```
SendGrid:  https://yourdomain.com/webhooks/email?provider=sendgrid
Mailgun:   https://yourdomain.com/webhooks/email?provider=mailgun
Postmark:  https://yourdomain.com/webhooks/email?provider=postmark
Amazon SES: https://yourdomain.com/webhooks/email?provider=amazon_ses
```

### Events Tracked
- **Delivered**: Email successfully delivered to recipient
- **Bounced**: Email bounced (hard or soft)
- **Opened**: Recipient opened the email
- **Clicked**: Recipient clicked a link
- **Spam Report**: Recipient marked as spam
- **Unsubscribed**: Recipient unsubscribed

### Example Usage

Webhooks are processed automatically when providers send callbacks. No manual code required. View webhook activity in the admin panel:

**Admin → Email Webhooks** ([/admin/email-webhooks](public_html/admin/email-webhooks.php))

---

## 🧪 2. A/B Testing Framework

### Purpose
Test different email variations to optimize performance (subject lines, content, from names, send times).

### Features
- **Multi-variant Testing**: A/B/C/D/etc. testing
- **Automatic Variant Assignment**: Hash-based consistent assignment
- **Statistical Significance**: Chi-square test for valid results
- **Winner Selection**: Manual or automatic winner selection
- **Performance Analytics**: Open rates, click rates, conversion tracking

### Files
- [_includes/email/EmailABTesting.php](_includes/email/EmailABTesting.php)
- [_database/migrations/002_email_ab_testing.sql](_database/migrations/002_email_ab_testing.sql)

### Creating a Test

```php
use EmailABTesting;

// Create A/B test
$testID = EmailABTesting::createTest([
    'test_name' => 'Welcome Email Subject Test',
    'test_type' => 'subject',
    'description' => 'Testing different subject lines',
    'sample_size_percentage' => 100,
    'winner_metric' => 'open_rate',
    'auto_select_winner' => true,
    'variants' => [
        [
            'label' => 'Short Subject',
            'subject' => 'Welcome to SIGNula!',
            'bodyHTML' => '<h1>Welcome!</h1>...',
            'traffic_percentage' => 50
        ],
        [
            'label' => 'Descriptive Subject',
            'subject' => 'Welcome to SIGNula - Your Universal Account',
            'bodyHTML' => '<h1>Welcome!</h1>...',
            'traffic_percentage' => 50
        ]
    ],
    'created_by' => $userID
]);

// Start the test
EmailABTesting::startTest($testID);

// Send test emails
EmailABTesting::sendTestEmails($testID, [
    'user1@example.com',
    'user2@example.com',
    // ... more recipients
], $userID);

// Get results
$results = EmailABTesting::getTestResults($testID);
echo "Winner: " . $results['winner']['variantLabel'];
echo "Open Rate: " . $results['winner']['openRate'] . "%";

// Select winner (manual or automatic)
EmailABTesting::selectWinner($testID); // Auto-select based on metric
```

### Test Types
- **Subject Line**: Test different subject lines
- **Content**: Test different email body content
- **From Name**: Test different sender names
- **Send Time**: Test different send times
- **Multi**: Test multiple elements simultaneously

---

## 💧 3. Drip Campaign Automation

### Purpose
Automated email sequences triggered by events or time delays (onboarding, nurture campaigns, re-engagement).

### Features
- **Event-triggered Campaigns**: Triggered by user actions
- **Time-based Delays**: Days, hours, weeks between emails
- **Conditional Logic**: Send based on previous email engagement
- **Subscriber Management**: Pause, resume, unsubscribe
- **Goal Tracking**: Measure campaign success

### Files
- [_includes/email/EmailDripCampaign.php](_includes/email/EmailDripCampaign.php)
- [_includes/email/EmailDripProcessor.php](_includes/email/EmailDripProcessor.php)
- [_database/migrations/003_email_drip_campaigns.sql](_database/migrations/003_email_drip_campaigns.sql)

### Creating a Drip Campaign

```php
use EmailDripCampaign;

// 1. Create campaign
$campaignID = EmailDripCampaign::createCampaign([
    'campaignName' => 'Welcome Series',
    'description' => '3-part welcome email series',
    'triggerType' => 'event',
    'triggerEvent' => 'user_signup',
    'goalType' => 'open',
    'createdBy' => $userID
]);

// 2. Add campaign steps
EmailDripCampaign::addCampaignStep($campaignID, [
    'stepOrder' => 1,
    'stepName' => 'Welcome Email',
    'delayValue' => 0,
    'delayUnit' => 'days',
    'templateID' => $welcomeTemplateID,
    'conditionType' => 'always'
]);

EmailDripCampaign::addCampaignStep($campaignID, [
    'stepOrder' => 2,
    'stepName' => 'Getting Started',
    'delayValue' => 2,
    'delayUnit' => 'days',
    'templateID' => $gettingStartedTemplateID,
    'conditionType' => 'opened_previous'
]);

EmailDripCampaign::addCampaignStep($campaignID, [
    'stepOrder' => 3,
    'stepName' => 'Tips & Tricks',
    'delayValue' => 5,
    'delayUnit' => 'days',
    'templateID' => $tipsTemplateID,
    'conditionType' => 'always'
]);

// 3. Add subscriber
EmailDripCampaign::addSubscriber(
    campaignID: $campaignID,
    email: 'user@example.com',
    userID: $userID,
    customData: [
        'firstName' => 'John',
        'signupSource' => 'website'
    ]
);

// 4. Get campaign statistics
$stats = EmailDripCampaign::getCampaignStatistics($campaignID);
```

### Processing Drip Emails

Set up a cron job to process due emails:

```bash
# Process drip campaigns every 15 minutes
*/15 * * * * php /path/to/EmailDripProcessor.php -v >> /var/log/drip-processor.log 2>&1
```

### Subscriber Management

```php
// Pause subscriber
EmailDripCampaign::pauseSubscriber($subscriberID);

// Resume subscriber
EmailDripCampaign::resumeSubscriber($subscriberID);

// Unsubscribe
EmailDripCampaign::unsubscribe($subscriberID, 'user_request');
```

---

## 🎨 4. Advanced Personalization Engine

### Purpose
Dynamic email personalization based on user data, behavior, and context.

### Features
- **User Data Personalization**: Display name, email, timezone
- **Behavioral Personalization**: Based on email engagement
- **Dynamic Content Blocks**: Generate personalized content
- **Conditional Rendering**: Show/hide content based on conditions
- **Loop Processing**: Repeat content for lists
- **Recommendation Engine**: Personalized content suggestions

### Files
- [_includes/email/EmailPersonalization.php](_includes/email/EmailPersonalization.php)

### Personalization Tags

#### User Variables
```
{{user.displayName}}  - User's display name
{{user.firstName}}    - User's first name
{{user.lastName}}     - User's last name
{{user.email}}        - User's email address
```

#### Date/Time Variables
```
{{date.now}}          - Current date (Y-m-d)
{{date.today}}        - Current date (long format)
{{date.time}}         - Current time
{{time.greeting}}     - Time-based greeting (Good morning/afternoon/evening)
```

#### Custom Variables
```
{{custom.anyVariable}} - Custom data passed to personalization
```

### Example Usage

```php
use EmailPersonalization;

// Basic personalization
$content = "Hello {{user.firstName}}, welcome to SIGNula!";
$personalized = EmailPersonalization::personalize(
    content: $content,
    userID: $userID
);
// Output: "Hello John, welcome to SIGNula!"

// Advanced personalization with conditionals
$content = "
    {{time.greeting}}, {{user.displayName}}!

    {{if:behavior.emails_opened > 0}}
        <p>Thanks for being an engaged subscriber!</p>
    {{endif}}

    {{if:behavior.emails_opened == 0}}
        <p>We noticed you haven't opened our emails yet.
        Here's what you're missing...</p>
    {{endif}}
";

$personalized = EmailPersonalization::personalize(
    content: $content,
    userID: $userID
);

// Loop through items
$content = "
    <h3>Recommended Products:</h3>
    <ul>
    {{each:products}}
        <li>{{item.name}} - ${{item.price}}</li>
    {{endeach}}
    </ul>
";

$personalized = EmailPersonalization::personalize(
    content: $content,
    userID: $userID,
    customData: [
        'products' => [
            ['name' => 'Product A', 'price' => '29.99'],
            ['name' => 'Product B', 'price' => '49.99']
        ]
    ]
);
```

### Conditional Logic

Supported operators:
- `exists` / `not exists`
- `==` / `!=`
- `>` / `<` / `>=` / `<=`

Examples:
```
{{if:user.firstName exists}}...{{endif}}
{{if:behavior.emails_opened > 5}}...{{endif}}
{{if:custom.membershipLevel == "premium"}}...{{endif}}
```

---

## ⏰ 5. Enhanced Scheduling & Timezone Support

### Purpose
Timezone-aware email scheduling with optimal send time calculation.

### Features
- **Timezone Conversion**: Automatic UTC conversion for storage
- **Optimal Send Times**: Calculate best time based on engagement
- **Business Hours**: Respect business days and hours
- **Batch Scheduling**: Schedule multiple emails with staggered times
- **Recurring Schedules**: Daily, weekly, monthly recurrence
- **Multi-timezone Support**: Send at local time for each recipient

### Files
- [_includes/email/EmailScheduler.php](_includes/email/EmailScheduler.php)
- [_database/migrations/004_email_recurring_schedules.sql](_database/migrations/004_email_recurring_schedules.sql)

### Basic Scheduling

```php
use EmailScheduler;

// Schedule for specific time
$scheduledTime = new DateTime('2026-02-03 10:00:00', new DateTimeZone('America/New_York'));

$queueID = EmailScheduler::scheduleEmail(
    emailData: [
        'recipientEmail' => 'user@example.com',
        'subject' => 'Scheduled Email',
        'bodyHTML' => '<p>This email was scheduled!</p>',
        'bodyText' => 'This email was scheduled!'
    ],
    scheduledFor: $scheduledTime,
    timezone: 'America/New_York'
);
```

### Optimal Send Time

```php
// Automatically calculate best time for recipient
$queueID = EmailScheduler::scheduleAtOptimalTime(
    emailData: [
        'recipientEmail' => 'user@example.com',
        'subject' => 'Optimized Email',
        'bodyHTML' => '<p>Sent at your optimal time!</p>',
        'bodyText' => 'Sent at your optimal time!'
    ],
    userID: $userID
);
```

### Multi-timezone Scheduling

```php
// Send at 10 AM local time for each recipient
$recipients = [
    ['email' => 'user1@example.com', 'timezone' => 'America/New_York'],
    ['email' => 'user2@example.com', 'timezone' => 'Europe/London'],
    ['email' => 'user3@example.com', 'timezone' => 'Asia/Tokyo']
];

$queueIDs = EmailScheduler::scheduleForMultipleTimezones(
    recipients: $recipients,
    emailData: [
        'subject' => 'Good morning!',
        'bodyHTML' => '<p>Sent at 10 AM your local time</p>',
        'bodyText' => 'Sent at 10 AM your local time'
    ],
    localHour: 10
);
```

### Recurring Schedules

```php
// Create daily recurring email
$scheduleID = EmailScheduler::createRecurringSchedule([
    'scheduleName' => 'Daily Newsletter',
    'emailData' => [
        'recipientEmail' => 'subscribers@example.com',
        'subject' => 'Daily Update - {{date.today}}',
        'bodyHTML' => '<p>Your daily update</p>',
        'bodyText' => 'Your daily update'
    ],
    'frequency' => 'daily',
    'frequencyValue' => 1,
    'timeOfDay' => '09:00:00',
    'timezone' => 'America/New_York',
    'startDate' => '2026-02-03',
    'endDate' => null // No end date
]);

// Process recurring schedules (via cron)
EmailScheduler::processRecurringSchedules();
```

### Business Hours Adjustment

```php
// Adjust to business hours (9 AM - 5 PM, weekdays only)
$time = new DateTime('2026-02-01 22:00:00'); // Saturday 10 PM

$adjusted = EmailScheduler::adjustToBusinessHours($time);
// Result: Monday 9:00 AM

$adjusted = EmailScheduler::adjustToBusinessDay($time);
// Result: Monday at same time
```

---

## 📊 6. Webhook Management UI

### Purpose
Admin interface for managing email provider webhooks and viewing webhook activity.

### Features
- **Configuration Display**: Shows webhook URLs for all providers
- **Copy to Clipboard**: Easy webhook URL copying
- **Activity Logs**: View recent webhook events
- **Statistics**: Webhook success rates by provider
- **Testing**: Test webhook endpoints

### Access

**URL:** `/admin/email-webhooks`
**File:** [public_html/admin/email-webhooks.php](public_html/admin/email-webhooks.php)

### Features

1. **Webhook Statistics** (Last 24 Hours)
   - Total webhooks received per provider
   - Success/failure counts
   - Success rate percentage

2. **Webhook Configuration**
   - Copy webhook URLs
   - Provider status indicators
   - Test webhook functionality

3. **Recent Activity Log**
   - Event timestamps
   - Provider and event type
   - Associated email details
   - Success/failure status
   - Detailed log viewer

---

## 📦 Installation

### Database Migrations

Run the following migrations in order:

```sql
SOURCE _database/migrations/002_email_ab_testing.sql;
SOURCE _database/migrations/003_email_drip_campaigns.sql;
SOURCE _database/migrations/004_email_recurring_schedules.sql;
```

### Cron Jobs

Add these cron jobs for automated processing:

```bash
# Email queue processor (every 5 minutes)
*/5 * * * * php /path/to/EmailQueueProcessor.php -v >> /var/log/email-queue.log 2>&1

# Drip campaign processor (every 15 minutes)
*/15 * * * * php /path/to/EmailDripProcessor.php -v >> /var/log/drip-processor.log 2>&1

# Provider health monitor (every 15 minutes)
*/15 * * * * php /path/to/EmailProviderHealthMonitor.php >> /var/log/provider-health.log 2>&1
```

### Settings

Enable features in database settings:

```sql
-- Enable A/B testing
INSERT INTO tblSettings (settingKey, settingValue, category)
VALUES ('email.ab_testing.enabled', '1', 'email');

-- Enable drip campaigns
INSERT INTO tblSettings (settingKey, settingValue, category)
VALUES ('email.drip_campaigns.enabled', '1', 'email');

-- Enable recurring schedules
INSERT INTO tblSettings (settingKey, settingValue, category)
VALUES ('email.recurring_schedules.enabled', '1', 'email');
```

---

## 🔧 Configuration

### Webhook Signature Keys

Store webhook signature keys in settings:

```php
// SendGrid
setSetting('email.sendgrid.webhook_secret', SecurityUtils::encrypt('your_secret'));

// Mailgun
setSetting('email.mailgun.webhook_signing_key', SecurityUtils::encrypt('your_key'));

// Postmark
setSetting('email.postmark.webhook_secret', SecurityUtils::encrypt('your_secret'));
```

### Timezone Settings

Set default timezone:

```php
setSetting('site.timezone', 'America/New_York');
```

User timezones are stored in `tblUsers.timezone` field.

---

## 📈 Analytics & Reporting

### A/B Test Results

```php
$results = EmailABTesting::getTestResults($testID);

echo "Test: " . $results['test']['testName'] . "\n";
echo "Status: " . $results['test']['status'] . "\n\n";

foreach ($results['variants'] as $variant) {
    echo "Variant: " . $variant['variantLabel'] . "\n";
    echo "Sent: " . $variant['sentCount'] . "\n";
    echo "Open Rate: " . $variant['openRate'] . "%\n";
    echo "Click Rate: " . $variant['clickRate'] . "%\n\n";
}

echo "Winner: " . $results['winner']['variantLabel'] . "\n";
echo "Statistical Significance: " .
     ($results['statistical_significance']['is_significant'] ? 'Yes' : 'No') . "\n";
```

### Drip Campaign Statistics

```php
$stats = EmailDripCampaign::getCampaignStatistics($campaignID);

echo "Subscribers: " . $stats['subscribers']['total_subscribers'] . "\n";
echo "Active: " . $stats['subscribers']['active'] . "\n";
echo "Completed: " . $stats['subscribers']['completed'] . "\n";
echo "Open Rate: " . $stats['open_rate'] . "%\n";
echo "Click Rate: " . $stats['click_rate'] . "%\n";
```

---

## 🔐 Security Considerations

1. **Webhook Signatures**: Always verify webhook signatures before processing
2. **Encrypted Credentials**: All API keys and secrets are encrypted in database
3. **Rate Limiting**: Webhook endpoints should implement rate limiting
4. **Input Validation**: All webhook data is validated before processing
5. **SQL Injection**: All queries use prepared statements

---

## ✅ Best Practices

### A/B Testing
- Minimum 100 recipients per variant for statistical significance
- Test one element at a time (subject, content, etc.)
- Let tests run until statistically significant
- Document test results for future reference

### Drip Campaigns
- Keep campaigns focused on single goal
- Use conditional logic for engagement-based paths
- Monitor unsubscribe rates
- Test campaigns with small group first

### Personalization
- Always provide fallback values for missing data
- Test personalized content before mass sending
- Respect user privacy and preferences
- Use meaningful variable names

### Scheduling
- Always store times in UTC
- Consider recipient timezones
- Respect business hours for professional emails
- Use optimal send time feature for better engagement

---

## 📞 Support & Resources

### Documentation
- [Core Email System](EMAIL_SYSTEM.md)
- [Database Schema](_database/email_schema.sql)
- [API Reference](API_DOCUMENTATION.md)

### Provider Documentation
- [SendGrid Webhooks](https://docs.sendgrid.com/for-developers/tracking-events/event)
- [Mailgun Webhooks](https://documentation.mailgun.com/en/latest/api-webhooks.html)
- [Postmark Webhooks](https://postmarkapp.com/developer/webhooks)
- [Amazon SES SNS](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns.html)

---

**Version:** 3.0.0
**Last Updated:** 2026-02-02
**License:** Proprietary - SIGNula Project
**Maintainer:** MWBM Partners Ltd
