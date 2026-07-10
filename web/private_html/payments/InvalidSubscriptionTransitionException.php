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
 * 🚫 SIGNula - Illegal Subscription State Transition Exception
 * ============================================================================
 *
 * Purpose:
 *   Raised by SubscriptionStateMachine::assertTransition() / ::transition()
 *   whenever code attempts to move a tblSubscriptions row to a
 *   subscriptionStatus that the documented lifecycle (G-002 spec §5) does not
 *   permit from its current status — e.g. trying to jump straight from
 *   'expired' to 'active' without going through reactivateSubscription()'s
 *   documented 'cancelled'/'grace' → 'active' paths, or referencing an
 *   unrecognised status string entirely (typo protection).
 *
 *   Also raised when the target subscriptionID does not exist, so callers get
 *   a single, unambiguous failure type for "this state change is not allowed"
 *   rather than a generic \Exception.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.9.0-beta (G-002 Stage S1)
 *
 * @see web/private_html/payments/SubscriptionStateMachine.php — the class that throws this
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
 * 🚫 Raised when an illegal subscription lifecycle transition is attempted,
 * or the subscription/target status referenced does not exist/is unknown.
 *
 * Extends RuntimeException so existing catch(RuntimeException) / catch(\Exception)
 * blocks still handle it safely, while a dedicated catch(InvalidSubscriptionTransitionException)
 * site can special-case it (e.g. to surface a clearer admin-facing message).
 */
class InvalidSubscriptionTransitionException extends RuntimeException
{
}

// ✅ InvalidSubscriptionTransitionException loaded successfully
