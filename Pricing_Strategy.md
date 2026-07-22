# SIGNula ID — Pricing & Monetization Strategy

**Status:** Proposal v1.0 (launch-ready draft)
**Date:** 2026-07-22
**Owner:** MWBM Partners Ltd (t/a MWservices)
**Scope:** SIGNula.id universal SSO / identity platform — first-party apps, third-party partner apps, and end-user premium features.

> ⚠️ **All competitor figures in this document are "as of mid-2026" and MUST be re-verified against the vendors' live pricing pages immediately before launch.** Identity-platform pricing changed repeatedly during 2025–2026 (e.g. Clerk moved from 10,000 free MAU to 50,000 free MRU in February 2026). Treat every number below as a research snapshot, not a contract.

---

## 1. Executive Summary

SIGNula sells **trust infrastructure**: authentication, MFA, passkeys, account linking, and an OIDC IdP that other apps (first-party and third-party) integrate. The monetization model that fits this product — and that the market has converged on — is:

1. **A genuinely useful free tier** (free-tier-led growth). Identity is a winner-take-most integration: once an app wires "Sign in with SIGNula", switching costs are high. The free tier is the acquisition engine; nobody integrates an identity platform they can't try for free.
2. **Meter primarily on MAU (monthly active users)**, the industry-standard axis, with **feature-gating as the second axis** (which capabilities a plan unlocks) and **add-ons/metered usage as the third** (extra MAU packs, API-call overage, SSO connections, compliance pack).
3. **Never paywall baseline security.** MFA, passkeys, and breached-password protection are included at every tier including Free. This is both a modern-market expectation (Stytch, Clerk, Supabase include MFA free; Auth0's gating of MFA is widely resented as the "security tax") and on-brand for a product whose brief promises "extremely secure in today's online-security-focused era". What we sell is *scale, integration depth, compliance tooling, and operational features* — not safety.
4. **Maximal catalog flexibility, minimal hard-coding.** Every tier, feature, price, discount, and add-on in this document is a **starting suggestion**, stored as *data* in the flexible catalog schema (see companion doc `pricing_schema_design.md`). Changing the ladder, adding a tier, or re-pricing a region must never require a code change.
5. **Ship the machinery dormant.** The entitlement enforcement layer (FG-011) ships **built but disabled** (fail-open) behind a global switch, so deploying it changes nothing until the business flips it on.

### Who pays whom (three revenue surfaces)

| Surface | Customer | What they buy | Primary axis |
|---|---|---|---|
| **Partner / developer plans** (main revenue) | Apps integrating SIGNula (third-party partners; internal apps at £0 internal transfer) | Auth capacity + platform features for *their* users | MAU + features |
| **End-user premium** (secondary, optional) | Individual SIGNula ID holders | Personal upgrades (extended history, premium support, early features) | Feature-gating only |
| **Partner-defined tiers** (pass-through) | Partners' own customers | Whatever the partner defines in `tblPartnerSubscriptionTiers` | Partner's choice; SIGNula takes service fees (`tblServiceFees`) |

This document focuses on the first surface (it is where identity platforms make money), while the schema supports all three.

---

## 2. Competitive Landscape (as of 2026 — re-verify before launch)

| Provider | Free tier shape | Primary metering axis | Paid entry point | Enterprise-only / top-tier gates | Notes |
|---|---|---|---|---|---|
| **Auth0 (Okta CIC)** | 25,000 MAU, but crippled: no MFA, no custom domain, dev-grade support | MAU, split B2C vs B2B price books | B2C Essentials ~$35/mo (500 MAU); B2C Professional ~$240/mo (1k MAU); B2B Essentials ~$150/mo; overage ~$0.07/MAU | Higher SLAs, advanced attack protection, private deployment, custom contracts; enterprise ~$3,000/mo minimum (10k B2C MAU ≈ $700–1,600/mo) | The cautionary tale: costs escalate sharply with MAU; "MFA is paid" is its most-criticised gate |
| **Clerk** | 50,000 **MRU** free (raised from 10,000 MAU, Feb 2026) | MRU (monthly *retained* users — narrower than MAU) | Pro $25/mo + per-user overage (~$1,025/mo at 100k retained) | SAML SSO sold per-connection as add-on; enterprise custom | Component/UI-led DX; MRU metric makes headline free tier look bigger than MAU-metered rivals |
| **WorkOS** | AuthKit free to **1,000,000 MAU** (incl. email/password, social, MFA) | **Per enterprise SSO/SCIM connection** (not MAU) | $125/connection/mo (1–15), sliding to $50/connection (101–200), custom above | Audit-log streaming, Directory Sync priced per connection | The "anti-MAU" model: auth is free, you pay when an enterprise customer needs SAML/SCIM |
| **Stytch** | ~25,000 MAU free; **all features available free** (MFA, SSO, RBAC, SCIM) | MAU, pure pay-as-you-go (no hard caps) | Usage-based from $0; branding-removal tier ~$249/mo; Scale ~$799/mo | Volume contracts, SLAs | Closest to "everything free, pay for scale" |
| **FusionAuth** | Community: free, **unlimited users** (self-hosted) | **Features + hosting environment**, not MAU | Licenses from ~$125–162/mo; cloud hosting from ~$37/mo/environment; up to ~$2,970/mo | Advanced threat detection, enterprise support | Self-host escape hatch is its differentiator |
| **Firebase Auth / GCP Identity Platform** | 50,000 MAU free | MAU (tiered unit price) | ~$0.0055/MAU falling to ~$0.0025/MAU at volume; SMS billed separately | SAML/OIDC federation ~$0.015/MAU (premium); SLA via GCP | Cheapest per-MAU at scale; minimal platform features |
| **Supabase Auth** | 50,000 MAU free (bundled with platform) | MAU bundled into platform plan | Pro $25/mo incl. 100k MAU, then ~$0.00325/MAU | Team $599/mo (SOC 2, SSO, backups); enterprise custom | Auth as a loss-leader for the platform |
| **Okta (Workforce)** | Trial only | Per seat/user/month | ~$6/user/mo (SSO+MFA bundles), ~$1,500/yr minimum | Governance, PAM, custom contracts | B2E model — relevant only as contrast |
| **Frontegg** | ~7,500 MAU free ("Launch") | MAU + feature plans; has an **entitlements engine** | Gated/sales-led; custom | Multi-tenant admin portal, entitlements at top tiers | Lower confidence — pricing largely behind sales; re-verify |

### Patterns to copy (and one to avoid)

- **MAU is the lingua franca.** Every self-serve competitor except WorkOS/FusionAuth meters on active users. Buyers can comparison-shop MAU; pick it as the primary axis.
- **The "SSO tax" is real and lucrative but resented.** Enterprise SAML/SCIM is either top-tier-gated (Auth0, Supabase Team) or a per-connection add-on (WorkOS $125/conn, Clerk). Recommendation: sell **SSO/enterprise connections as an add-on** (WorkOS-style) rather than forcing a whole-tier jump — friendlier, and the add-on schema supports it natively.
- **Generous free tiers are the 2026 norm** (50k at Firebase/Supabase/Clerk, 1M at WorkOS). SIGNula on shared hosting cannot subsidise 50k MAU per tenant at launch — start honest (5k) and raise the free ceiling as infrastructure allows; the ceiling is one number in one catalog row.
- **Avoid Auth0's mistake:** never gate MFA/security behind payment.

---

## 3. Proposed SIGNula Tier Ladder (starting suggestion — 100% reconfigurable data)

> The repo already seeds Free / Basic / Premium / Pro / Platinum / Enterprise in `tblSubscriptionTiers` (£0 / £9.99 / £29.99 / £49.99 / £79.99 / £99.99). The ladder below is a **rationalised 5-tier replacement** aligned to competitor positioning. Migration: keep existing rows, mark Basic/Premium/Platinum `isActive = 0` (built-but-disabled), retitle/re-seed per below — all catalog edits, no code.

Prices in GBP. Annual = 10× monthly (2 months free, the industry-standard annual discount, modelled as a separate price row — see §5). All numbers are launch anchors, editable in the catalog at any time.

| Tier | Target user | MAU allowance | Monthly | Annual | 1-line pitch |
|---|---|---|---|---|---|
| **Free** | Hobbyists, evaluation, early internal apps | 5,000 | £0 | £0 | Full-strength auth — every MFA method, passkeys, social login — free forever |
| **Starter** | Indie devs, small apps going to production | 10,000 | £19 | £190 | Production polish: custom branding, webhooks, email support |
| **Pro** ("Popular" badge) | Growing businesses, agencies, multi-app teams | 25,000 | £49 | £490 | Organizations, RBAC, your own OAuth/OIDC clients, audit export, PAYG overage |
| **Business** ("Best Value" badge) | Scale-ups with compliance/enterprise-customer needs | 100,000 | £149 | £1,490 | Enterprise SSO, SCIM, compliance pack, SIEM streaming, 99.9% SLA |
| **Enterprise** | Regulated / high-scale / bespoke | Custom (unlimited) | Contact us (anchor ≥ £500/mo) | Custom | White-label, data residency, custom contracts & DPAs, dedicated support |

- **Trials:** 14 days on Starter/Pro, 30 days on Business (`trialDays` per tier — column already exists).
- **MAU overage:** none on Free/Starter (hard stop + upgrade prompt); Pro/Business get metered overage at £0.005/MAU (Pro) and £0.003/MAU (Business) — configured in `tblUsageRates` against the existing `active_users` metric.
- **Internal first-party apps** run on a comped Enterprise-equivalent grant (an entitlement grant with a 100% account-locked discount — see §5), so first-party and third-party apps exercise the identical code path.

---

## 4. Feature → Tier Matrix (starting suggestion)

Legend: ✅ included · ➕ paid add-on available · — not available. Every cell below becomes a row in `tblPlanFeatures` (per-plan entitlement value), never a code branch.

### Table stakes (free for everyone — never gate these)

| Capability (feature key) | Free | Starter | Pro | Business | Enterprise |
|---|---|---|---|---|---|
| Email/password + passwordless email link/OTP (`auth.core`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| **All MFA methods** — TOTP, backup codes, email OTP (`mfa.all`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Passkeys / WebAuthn** (`auth.passkeys`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Breached-password protection (`security.hibp`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Social/OAuth account linking — # providers (`oauth.link_providers`) | 3 | All | All | All | All |
| Registered apps / OAuth clients (`apps.max`) | 2 | 5 | 20 | 100 | Unlimited |
| MAU allowance (`mau.included`) | 5,000 | 10,000 | 25,000 | 100,000 | Unlimited |
| API rate limit, req/min (`api.rate_limit`) | 60 | 300 | 1,000 | 5,000 | Custom |
| API calls/month (`api.calls_month`) | 50k | 250k | 1M ➕ | 5M ➕ | Unlimited |
| Activity-log retention, days (`logs.retention_days`) | 30 | 90 | 365 | 730 | Custom |
| Webhooks (`webhooks.enabled` / `webhooks.max_endpoints`) | 1 | 5 | 20 | 100 | Unlimited |
| Community support (`support.level`) | ✅ | Email | Priority email | Priority + phone | Dedicated |

### Premium (the Starter/Pro upsell)

| Capability | Free | Starter | Pro | Business | Enterprise |
|---|---|---|---|---|---|
| Custom branding on hosted login/emails (`branding.custom`) | — | ✅ | ✅ | ✅ | ✅ |
| Remove "Secured by SIGNula" badge (`branding.remove_badge`) | — | — | ✅ | ✅ | ✅ |
| Organizations / teams (`orgs.enabled`, `orgs.max`) | — | 1 | 10 | 100 | Unlimited |
| Seats per org (`orgs.seats`) | — | 5 | 25 | 200 | Unlimited |
| RBAC / fine-grained permissions (`authz.rbac`) | — | — | ✅ | ✅ | ✅ |
| Act as OIDC IdP for partner app's users (`idp.oidc_clients`) | 1 test | 2 | 10 | 50 | Unlimited |
| Custom email templates (`email.custom_templates`) | — | ✅ | ✅ | ✅ | ✅ |
| Usage analytics dashboard (`analytics.dashboard`) | — | — | ✅ | ✅ | ✅ |
| Audit-log export CSV/PDF (`logs.export`) | — | — | ✅ | ✅ | ✅ |
| MAU overage (PAYG) instead of hard cap (`mau.overage`) | — | — | ✅ | ✅ | ✅ |

### Enterprise-grade (Business/Enterprise; several also sold as add-ons ➕)

| Capability | Free | Starter | Pro | Business | Enterprise |
|---|---|---|---|---|---|
| Enterprise SSO — customer's own SAML/OIDC IdP inbound (`sso.enterprise_connections`) | — | — | ➕ | 5 incl. ➕ | Unlimited |
| SCIM provisioning (`scim.enabled`) | — | — | ➕ | ✅ | ✅ |
| Compliance pack — DSAR queue, consent tooling, RoPA, regime resolver (`compliance.pack`) | Self-serve DSAR only | Self-serve | ➕ | ✅ | ✅ |
| SIEM / audit-log streaming (`logs.siem_stream`) | — | — | — | ✅ | ✅ |
| Adaptive / risk-based MFA (`mfa.adaptive`) | — | — | ✅ | ✅ | ✅ |
| SLA (`sla.level`) | — | — | — | 99.9% | Custom (≥99.95%) |
| White-label / full custom domain (`branding.whitelabel`) | — | — | — | ➕ | ✅ |
| Data-residency options (`data.residency`) | — | — | — | — | ✅ |
| Custom contracts / DPA / security review (`legal.custom`) | — | — | — | — | ✅ |
| Dedicated account manager (`support.dedicated`) | — | — | — | — | ✅ |

> **Note on end-user premium (secondary surface):** a small "SIGNula Plus" personal plan (~£2.99/mo: extended personal activity history, priority support, early features, extra profile storage) can ride on the same catalog — it is just another plan row with user-scoped features. Recommend building the rows, keeping `isActive = 0` at launch.

---

## 5. Pricing Models the Platform Must Support (definitions)

The brief demands maximal flexibility. Each model below is precisely defined and maps onto the catalog schema (`pricing_schema_design.md`). A plan can carry **any number of price rows**; each row declares its currency, interval, and model.

| # | Model | Definition | Schema mapping |
|---|---|---|---|
| 1 | **Recurring — monthly/annual (also quarterly)** | Fixed amount charged every interval; auto-renews until cancelled; proration on plan change (G-002 S2 already built) | `tblPlanPrices` row: `pricingModel='flat'`, `billingInterval='month'|'quarter'|'year'` |
| 2 | **One-off** | Single charge granting the plan for a fixed term or a single deliverable; no renewal | `billingInterval='one_off'` + `termDays` |
| 3 | **Lifetime tier** | Single charge; entitlements never expire (subscription `billingCycle='lifetime'` already exists in the ENUM) | `billingInterval='lifetime'`, `termDays=NULL` |
| 4 | **Pay-as-you-go (metered)** | £0 or low base + per-unit charges from `tblUsageRates` (rate, free allowance, overage rate) settled each cycle — **already built in migration 018** (`billingMode='usage'`) | `pricingModel='metered'`; rates live in existing `tblUsageRates` |
| 5 | **PAYG-with-a-cap** ("never pay more than the flat plan") | Usage is billed until the cycle total equals the flat tier price, then further usage that cycle is free: `charge = MIN(totalUsageCost, capAmount)` — **already built** as `billingMode='hybrid'` with cap logic in `tblUsageBillingSummary` | `pricingModel='metered_capped'` + `capAmount`; existing hybrid engine executes it |
| 6 | **Free-forever** | £0 price row; default tier for new accounts (`isDefault`) | `unitAmount=0.00`, `pricingModel='flat'` |
| 7 | **Add-on purchase** | Independent product bought alongside any base plan (extra MAU pack, SSO connection, compliance pack); stackable quantities | `tblAddOns` + price rows with `productType='addon'` |

### Discount shapes (every one, precisely)

Existing base: `tblDiscountCodes` (percentage / fixed_amount / free_trial_days, redemption caps, validity window, per-tier + per-payment-method applicability), `tblDiscountCodeAssignments` (account-targeted codes), `tblProviderDiscounts` (payment-method % discounts). The schema doc extends these with **duration semantics, lifetime locking, categories, stacking policy, and an applied-instance table** — nothing is duplicated.

| Shape | Definition | Modelled as |
|---|---|---|
| **Introductory / limited-time** | Discount only redeemable inside a calendar window (e.g. "Launch month: 30% off") | Existing `validFrom`/`validUntil` + `discountCategory='intro'` |
| **First-N-months %** | X% off each of the first N billing periods, then full price | `duration='repeating'`, `durationPeriods=N`; per-subscription countdown in `tblAppliedDiscounts.remainingPeriods` |
| **Lifetime discount locked to an account** | X% off **forever**, bound to one SIGNula account, survives plan changes, non-transferable | `duration='forever'` + `isAccountLocked=1` + row in `tblDiscountCodeAssignments`; applied instance carries `lockedUserID` |
| **Annual-vs-monthly** | Annual price cheaper than 12× monthly | **Not a discount** — two independent price rows per plan (keeps invoices clean and avoids stacking ambiguity); display layer computes "save £X" |
| **Coupon / promo codes** | User-entered code at checkout | Existing `tblDiscountCodes.code` flow |
| **Volume** | Unit price falls at quantity thresholds (MAU bands, add-on quantity, seats) | `volumeTiers` JSON on the discount (or graduated `tblUsageRates` rows for metered units) |
| **Non-profit / education** | Standing % off gated on verified status | `discountCategory='nonprofit'|'education'` + `requiresVerification=1`; admin approves, then behaves as account-locked lifetime |
| **Crypto-payment discount** (brief-explicit) | % off when paying via configured crypto provider | **Already built**: `tblProviderDiscounts` (`paymentMethod='crypto*'`, global or per-partner) |
| **Grandfathering** | Existing subscribers keep their old price and/or old entitlement set after the catalog changes | `tblSubscriptions.isGrandfathered` + `entitlementSnapshot` JSON (schema doc §"grandfathering"); price side optionally as a `duration='forever'` applied discount |

**Stacking policy (recommended default):** at most one *code* discount + one *provider* discount per invoice; account-locked lifetime discounts stack with provider discounts but not with other codes; best-for-customer wins on conflict. Policy itself is a setting (`billing.discounts.stacking_policy`), not code.

---

## 6. Add-ons & Metered Units (independent of base tier)

Sold à la carte on top of any eligible plan; quantities stack; each has its own price rows and can be recurring or one-off.

| Add-on (key) | Unit | Suggested price | Eligible tiers |
|---|---|---|---|
| Extra MAU pack (`addon.mau_10k`) | +10,000 MAU/mo | £15/mo | Starter+ |
| Extra API calls (`addon.api_1m`) | +1,000,000 calls/mo | £10/mo | Pro+ |
| Enterprise SSO connection (`addon.sso_connection`) | 1 inbound SAML/OIDC connection | £60/mo each (undercut WorkOS's $125) | Pro+ |
| SCIM connection (`addon.scim_connection`) | 1 directory-sync connection | £40/mo each | Pro+ |
| Compliance pack (`addon.compliance_pack`) | DSAR queue, consent mgmt, RoPA, regimes | £39/mo | Pro |
| Extended log retention (`addon.log_retention_2y`) | Retention → 730 days | £19/mo | Starter+ |
| Extra organizations (`addon.orgs_10`) | +10 orgs | £9/mo | Pro+ |
| White-label (`addon.whitelabel`) | Remove all SIGNula branding + custom domain | £99/mo | Business |
| Priority support upgrade (`addon.support_priority`) | Support-level bump | £29/mo | Starter, Pro |

Metered units (PAYG/overage) ride the **existing** `tblUsageMetrics`/`tblUsageRates` machinery: `active_users`, `api_calls`, `emails_sent`, `storage_gb`, `auth_events`, `webhook_deliveries` are already seeded. Add-on quantity purchases simply raise the numeric entitlement the resolver computes (plan value + Σ add-ons).

---

## 7. Rollout Guidance

**Phase 0 — ship dormant (now).** Deploy catalog schema + `Entitlements` resolver with `entitlements.enforcement_enabled = false` (fail-open: every check returns "allowed"). Zero behavior change; `billing.test_mode_guard` stays ON (no live charges). Seed the full catalog (all tiers, features, prices, add-ons) with only Free `isActive = 1`.

**Phase 1 — soft launch (first paying partners).** Enable **Free / Pro / Business** self-serve (3 visible tiers converts better than 5; Starter stays built-but-disabled until Free-tier pressure shows demand; Enterprise is a contact-us card, not self-serve). Flip enforcement ON in *log-only* mode first (record would-be denials to `tblActivityLog` for a week), then enforce. Go live on payments per issue #70 (live keys + `billing.test_mode_guard = 0`). Launch discounts: intro code (30% off 3 months, `repeating/3`), crypto discount 10% via `tblProviderDiscounts`, annual price rows.

**Phase 2 — expand.** Enable Starter if Free→Pro gap is losing conversions; enable SSO/SCIM/compliance add-ons as G-001-adjacent features harden; enable PAYG/hybrid modes (`billing.usage.enabled = true`) once usage tracking has ≥1 month of clean data (`billing.usage.tracking_enabled` is already ON independently — start collecting now). Enable non-profit/education program (manual verification).

**Phase 3 — mature.** End-user "SIGNula Plus"; partner-defined tier revenue share via existing `tblServiceFees`/`tblRemittances`; regional currency price rows (USD/EUR); raise the Free MAU ceiling as hosting economics allow; consider MRU-style metering *only* if sales friction demands it (decision point — see below).

**Keep built-but-disabled at launch:** Starter tier, SIGNula Plus, PAYG/hybrid billing modes, volume discounts, SIEM streaming add-on, white-label add-on, Ko-fi/Patreon providers (donation surface, not subscription billing).

**Decisions for the owner to confirm:** (1) MAU as primary metering axis (vs Clerk-style MRU or WorkOS-style per-connection-only); (2) the £19–£149 anchor points; (3) SSO-as-add-on vs SSO-as-Business-gate; (4) never-paywall-MFA principle; (5) Free-tier MAU ceiling (5k now, raise later).

---

## Appendix — Research sources (all accessed 2026-07; re-verify before launch)

- Auth0/Okta CIC pricing: auth0.com/pricing; costbench.com/software/identity-access-management/auth0/; accessowl.com/blog/okta-cost
- Clerk pricing + Feb-2026 50k-MRU change: clerk.com/pricing; clerk.com/blog/new-pricing-plans; saasprices.net/blog/clerk-free-plan-changes
- WorkOS per-connection model: workos.com/pricing; clerk.com/articles/the-real-cost-of-enterprise-sso-per-connection-vs-per-mau-p-3
- Stytch self-serve pricing: stytch.com/blog/stytch-self-serve-pricing/; supertokens.com/blog/stytch-pricing
- FusionAuth: fusionauth.io/pricing; xpay.sh/saas-pricing/fusionauth/
- Firebase Auth / Identity Platform: metacto.com/blogs/the-complete-guide-to-firebase-auth-costs-setup-integration-and-maintenance
- Supabase: supabase.com/pricing; uibakery.io/blog/supabase-pricing
- Frontegg (low confidence, sales-gated): frontegg.com/pricing; security.toolsinfo.com/tool/frontegg
