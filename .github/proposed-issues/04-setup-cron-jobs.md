---
title: "🔴 CRITICAL: Set Up Production Cron Jobs"
labels: ["priority: critical", "type: deployment", "status: ready"]
assignees: []
---

## 🎯 Description

Configure server cron jobs for automated maintenance tasks (email queue, billing, cleanup).

## 📋 Cron Jobs to Configure

### 1. Email Queue Processor (Every 1 minute)
```cron
* * * * * php /path/to/web/public_html/cron/email-queue.php >> /var/log/signula/email-queue.log 2>&1
```
- Processes queued emails from `tblEmailQueue`
- Handles retries for failed deliveries
- Updates email status tracking

### 2. Daily Billing Processor (Daily at 2:00 AM)
```cron
0 2 * * * php /path/to/web/public_html/cron/billing.php >> /var/log/signula/billing.log 2>&1
```
- Processes usage-based billing
- Generates invoices for subscription renewals
- Handles tier overages and credits

### 3. Monthly Remittance (1st of month at 3:00 AM)
```cron
0 3 1 * * php /path/to/web/public_html/cron/remittance.php >> /var/log/signula/remittance.log 2>&1
```
- Calculates partner revenue shares
- Generates remittance reports
- Triggers payout workflows

### 4. Database Cleanup (Daily at 3:30 AM)
```cron
30 3 * * * php /path/to/web/public_html/cron/cleanup.php >> /var/log/signula/cleanup.log 2>&1
```
- Purges expired sessions
- Deletes old activity logs (>90 days)
- Cleans up temporary files
- Archives old email queue entries

## 📋 Setup Tasks

- [ ] Create log directory: `mkdir -p /var/log/signula`
- [ ] Set log permissions: `chmod 755 /var/log/signula`
- [ ] Add cron jobs: `crontab -e` (as web server user)
- [ ] Verify PHP CLI path: `which php` (may be `/usr/bin/php` or `/usr/local/bin/php`)
- [ ] Update paths in cron commands to match server
- [ ] Test each cron script manually first:
  ```bash
  php /path/to/web/public_html/cron/email-queue.php
  php /path/to/web/public_html/cron/billing.php
  php /path/to/web/public_html/cron/remittance.php
  php /path/to/web/public_html/cron/cleanup.php
  ```
- [ ] Set up log rotation (`/etc/logrotate.d/signula`):
  ```
  /var/log/signula/*.log {
      daily
      rotate 30
      compress
      delaycompress
      missingok
      notifempty
  }
  ```
- [ ] Monitor cron execution for first 48 hours
- [ ] Set up alerting for cron failures (optional)

## ⚠️ Notes

**Dreamhost Shared Hosting:**
- Use Dreamhost panel "Cron Jobs" interface (no CLI access)
- PHP binary: `/usr/local/bin/php`
- Logs must be in web-accessible directory or email output
- Maximum frequency: every 1 minute

## ✅ Acceptance Criteria

- [ ] All 4 cron jobs configured and running
- [ ] Logs being written successfully
- [ ] Email queue processing within 1 minute
- [ ] Billing processor runs daily without errors
- [ ] No orphaned processes or memory leaks
- [ ] Log rotation configured

## 📊 Priority

**Critical** - Email delivery and billing depend on cron jobs.

## ⏱️ Estimated Effort

1-2 hours (configuration + testing + monitoring)
