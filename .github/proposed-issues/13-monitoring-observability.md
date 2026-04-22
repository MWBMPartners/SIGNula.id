---
title: "🟢 MEDIUM: Set Up Monitoring & Observability"
labels: ["priority: medium", "type: infrastructure", "status: ready"]
assignees: []
---

## 🎯 Description

Configure comprehensive monitoring, error tracking, and analytics for production environment.

## 📋 Uptime Monitoring

**Service:** UptimeRobot / Pingdom / StatusCake

- [ ] Monitor main site: `https://signula.id`
- [ ] Monitor API: `https://signula.id/api/health`
- [ ] Monitor admin: `https://signula.id/admin`
- [ ] Alert email/SMS on downtime
- [ ] Create status page: `status.signula.id`
- [ ] Set check interval: 1 minute
- [ ] Configure alert contacts

## 📋 Error Tracking

**Service:** Sentry / Rollbar / Bugsnag

- [ ] Install Sentry SDK for PHP
- [ ] Configure DSN in `tblSettings`
- [ ] Set environment: `production`
- [ ] Enable error grouping
- [ ] Configure alert rules
- [ ] Integrate with Slack/email
- [ ] Set up release tracking
- [ ] Configure source maps

## 📋 Application Performance Monitoring (APM)

**Service:** New Relic / Datadog / AppDynamics

- [ ] Install APM agent
- [ ] Monitor PHP transaction times
- [ ] Monitor database queries
- [ ] Monitor external API calls
- [ ] Set up custom metrics
- [ ] Configure alerting thresholds
- [ ] Create performance dashboards

## 📋 Log Aggregation

**Service:** Logtail / Papertrail / Splunk

- [ ] Centralize PHP error logs
- [ ] Centralize MySQL slow query logs
- [ ] Centralize cron job logs
- [ ] Set up log retention (90 days)
- [ ] Configure log alerts
- [ ] Create saved searches
- [ ] Set up log parsing rules

## 📋 Analytics (Privacy-Respecting)

**Service:** Plausible / Fathom / Simple Analytics

- [ ] Install analytics script
- [ ] Configure domains
- [ ] Set up goals/events:
  - [ ] User registration
  - [ ] OAuth provider usage
  - [ ] MFA enrollment
  - [ ] Payment conversions
  - [ ] API usage
- [ ] Create public dashboard (optional)
- [ ] GDPR-compliant (no cookies)

## 📋 Database Monitoring

- [ ] Enable MySQL slow query log
- [ ] Monitor connection pool usage
- [ ] Track query execution times
- [ ] Monitor table sizes
- [ ] Set up disk space alerts
- [ ] Monitor replication lag (if applicable)

## 📋 Security Monitoring

- [ ] Monitor failed login attempts
- [ ] Track rate limit hits
- [ ] Monitor API key usage
- [ ] Alert on suspicious activity
- [ ] Track CSRF token failures
- [ ] Monitor webhook signature failures

## ✅ Acceptance Criteria

- [ ] Uptime monitoring active with alerts
- [ ] Error tracking capturing PHP errors
- [ ] APM providing performance insights
- [ ] Logs centralized and searchable
- [ ] Analytics tracking key events
- [ ] Dashboards created for stakeholders
- [ ] Alert channels configured (email, Slack)

## ⏱️ Estimated Effort

4-6 hours
