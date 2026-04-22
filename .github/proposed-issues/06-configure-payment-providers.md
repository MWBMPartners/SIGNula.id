---
title: "🟡 HIGH: Configure Live Payment Provider Credentials"
labels: ["priority: high", "type: deployment", "status: ready"]
assignees: []
---

## 🎯 Description

Configure production API keys for all payment providers. Test sandbox mode first, then enable live mode.

## 📋 Supported Payment Providers

### 1. Stripe
- [ ] Create Stripe account / activate live mode
- [ ] Generate API keys (Dashboard → Developers → API keys):
  - [ ] **Test Secret Key**: `sk_test_...`
  - [ ] **Test Publishable Key**: `pk_test_...`
  - [ ] **Live Secret Key**: `sk_live_...`
  - [ ] **Live Publishable Key**: `pk_live_...`
- [ ] Configure webhook endpoint: `https://signula.id/webhooks/stripe`
- [ ] Add to `tblSettings`:
  ```sql
  INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
  ('stripe_secret_key', '<encrypted_sk_live>', 1),
  ('stripe_publishable_key', 'pk_live_...', 0),
  ('stripe_webhook_secret', '<encrypted_whsec>', 1);
  ```

### 2. PayPal
- [ ] Create PayPal Business account
- [ ] Create REST API app (Dashboard → My Apps & Credentials)
  - [ ] **Sandbox Client ID/Secret**
  - [ ] **Live Client ID/Secret**
- [ ] Configure webhook: `https://signula.id/webhooks/paypal`
- [ ] Add to `tblSettings`:
  ```sql
  INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
  ('paypal_client_id', 'AXxxx...', 0),
  ('paypal_client_secret', '<encrypted_secret>', 1),
  ('paypal_mode', 'live', 0);
  ```

### 3. Coinbase Commerce
- [ ] Create Coinbase Commerce account
- [ ] Generate API key (Settings → API keys)
- [ ] Configure webhook: `https://signula.id/webhooks/coinbase`
- [ ] Add to `tblSettings`:
  ```sql
  INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
  ('coinbase_api_key', '<encrypted_key>', 1),
  ('coinbase_webhook_secret', '<encrypted_secret>', 1);
  ```

### 4. Ko-fi
- [ ] Verify Ko-fi page: `ko-fi.com/signula`
- [ ] Get webhook verification token (Settings → API)
- [ ] Configure webhook: `https://signula.id/webhooks/kofi`
- [ ] Add to `tblSettings`:
  ```sql
  INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
  ('kofi_verification_token', '<encrypted_token>', 1);
  ```

### 5. Patreon
- [ ] Create Patreon creator account
- [ ] Register OAuth app (Portal → Clients & API Keys)
- [ ] Configure webhook: `https://signula.id/webhooks/patreon`
- [ ] Add to `tblSettings`:
  ```sql
  INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
  ('patreon_client_id', 'xxx', 0),
  ('patreon_client_secret', '<encrypted_secret>', 1),
  ('patreon_webhook_secret', '<encrypted_secret>', 1);
  ```

## 📋 Testing Tasks

- [ ] Test Stripe payment flow (sandbox):
  - [ ] One-time payment
  - [ ] Recurring subscription
  - [ ] Webhook delivery
  - [ ] Refund processing
- [ ] Test PayPal checkout flow
- [ ] Test Coinbase crypto payment
- [ ] Test Ko-fi donation webhook
- [ ] Test Patreon membership webhook
- [ ] Verify all webhooks have valid HMAC signatures
- [ ] Test payment failure scenarios
- [ ] Test subscription cancellation flows

## ✅ Acceptance Criteria

- [ ] All 5 providers configured with live credentials
- [ ] Webhooks verified and processing correctly
- [ ] Test transactions completed successfully
- [ ] Credentials encrypted in database
- [ ] Payment records saved to `tblPayments`
- [ ] Invoice generation working
- [ ] Email receipts sent to customers
- [ ] Revenue tracking accurate

## 📊 Priority

**High** - Required for paid tier functionality.

## ⏱️ Estimated Effort

3-4 hours (account setup + configuration + testing)
