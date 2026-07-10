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
 * @version 2.7.0-beta
 */

/**
 * ============================================================================
 * 🚫 SIGNula - Illegal DSAR State Transition Exception
 * ============================================================================
 *
 * Purpose:
 *   Raised by DSARManager::assertTransition() / ::transition() whenever code
 *   attempts to move a tblDataSubjectRequests row to a `status` that the
 *   documented lifecycle (G-004 spec §2.5) does not permit from its current
 *   status — e.g. jumping straight from 'submitted' to 'fulfilled' without
 *   passing through identity verification and 'in_progress', trying to move
 *   a TERMINAL request (fulfilled/rejected/partially_fulfilled/expired/
 *   withdrawn) anywhere at all, or referencing an unrecognised status string
 *   entirely (typo protection).
 *
 *   Also raised when the target requestID does not exist, so callers get a
 *   single, unambiguous failure type for "this state change is not allowed"
 *   rather than a generic \Exception.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * @package    SIGNula
 * @subpackage Compliance
 * @version    1.0.0
 * @since      2.7.0-beta (G-004 Layer 1 / Cycle B)
 *
 * @see web/private_html/compliance/DSARManager.php — the class that throws this
 * @see web/private_html/payments/InvalidSubscriptionTransitionException.php — sibling dedicated-exception pattern this mirrors
 * @see .dev-team/specs/G-004.md §2.5 (the authoritative DSAR state machine this implements)
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
 * 🚫 Raised when an illegal DSAR lifecycle transition is attempted, or the
 * request/target status referenced does not exist/is unknown.
 *
 * Extends RuntimeException so existing catch(RuntimeException) / catch(\Exception)
 * blocks still handle it safely, while a dedicated catch(InvalidDSARTransitionException)
 * site can special-case it (e.g. to surface a clearer admin-facing message
 * from the Layer-1-Cycle-C admin queue, once it exists).
 */
class InvalidDSARTransitionException extends RuntimeException
{
}

// ✅ InvalidDSARTransitionException loaded successfully
