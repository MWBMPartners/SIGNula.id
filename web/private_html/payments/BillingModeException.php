<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.9.0-beta
 */

/**
 * ============================================================================
 * 🚫 SIGNula - Billing TEST-MODE Guard Exception
 * ============================================================================
 *
 * Purpose:
 *   Raised by BillingMode::assertTestMode() whenever code attempts to run a
 *   money-moving operation (charge, subscription create/revise, refund) against
 *   a payment provider that is configured in LIVE mode while the
 *   `billing.test_mode_guard` setting is still ON (the default).
 *
 *   This is the code-level expression of G-002 §0 — "the whole epic is
 *   TEST/SANDBOX ONLY; no real money moves; no live-charge code path may be
 *   reachable." Every provider entry point wired to BillingMode::assertTestMode()
 *   catches its own \Exception subtypes already (existing house pattern — see
 *   PayPalProvider::createSubscription()/refund(), StripeProvider::
 *   createCheckoutSession()/refund()), so a thrown BillingModeException is
 *   caught by that same try/catch and converted into the method's normal
 *   `['success' => false, ...]` failure response — the caller's contract never
 *   changes, but no provider HTTP call is ever reached first.
 *
 *   Going live is a separate, USER-GATED operational step (GitHub issue #70):
 *   the user sets live credentials AND explicitly flips
 *   `billing.test_mode_guard` to '0' via the admin Settings UI. This exception
 *   (and the guard that throws it) is the safety net that makes "we
 *   accidentally went live" impossible without that deliberate act.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.9.0-beta (G-002 Stage S1)
 *
 * @see web/private_html/payments/BillingMode.php — the guard that throws this
 * @see web/private_html/security/JwtException.php — sibling dedicated-exception pattern this mirrors
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access — all SIGNula files must be loaded via the bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🚫 Raised when a money-moving call is refused because a provider is in
 * LIVE mode while the TEST-MODE guard (billing.test_mode_guard) is ON.
 *
 * Extends RuntimeException (not a bespoke base) so any existing
 * catch(RuntimeException) / catch(\Exception) block still handles it safely,
 * while a dedicated catch(BillingModeException) site can special-case it
 * (e.g. to surface a clearer admin-facing message).
 */
class BillingModeException extends RuntimeException
{
}

// ✅ BillingModeException loaded successfully
