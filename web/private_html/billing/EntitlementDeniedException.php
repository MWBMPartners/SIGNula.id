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
 * 🚫 SIGNula - Entitlement Denial Exception (FG-011)
 * ============================================================================
 *
 * Purpose:
 *   Raised ONLY by Entitlements::require() and ONLY when ALL of the
 *   following are simultaneously true:
 *     1. `entitlements.enforcement_enabled` (tblSettings) is true,
 *     2. the `entitlement_enforcement` tblFeatureToggles row is enabled
 *        (globally, or for the calling partner via tblPartnerFeatures),
 *     3. `entitlements.log_only` is NOT true (i.e. enforcement is genuinely
 *        live, not in its "shadow week" logging-only phase), AND
 *     4. the resolved entitlement for the requested feature key is denied.
 *
 *   Every one of those gates defaults OFF (see migration
 *   046_flexible_pricing_catalog.sql), so — exactly like
 *   BillingModeException — this exception's very existence changes NO
 *   current behaviour: shipping Entitlements.php throws this class zero
 *   times until a human deliberately flips the gates above.
 *
 *   Callers (API controllers, page guards) catch this and translate it into
 *   their own transport-appropriate response — e.g. a JSON 402 "Payment
 *   Required" / 403 "Forbidden" via the existing `Response` class
 *   (web/private_html/api/Response.php) per the standardised B-011
 *   `{success, data|error}` envelope, or a redirect to an upgrade page for a
 *   traditional (non-AJAX) request. Entitlements.php deliberately does NOT
 *   import/depend on the API `Response` class itself — billing/ and api/
 *   are independent autoload buckets (see web/_config/config.php), and a
 *   feature guard may just as easily sit in front of a plain web page as an
 *   API endpoint.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * @package    SIGNula
 * @subpackage Billing
 * @version    1.0.0
 * @since      2.9.0-beta (FG-011)
 *
 * @see web/private_html/billing/Entitlements.php — the resolver that throws this
 * @see web/private_html/payments/BillingModeException.php — sibling dedicated-exception pattern this mirrors
 * @see pricing_schema_design.md §8 (the dormant-shipping / fail-open contract)
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
 * 🚫 Raised when Entitlements::require() genuinely denies a feature/limit
 * check — enforcement ON, toggle ON, log-only OFF, and the resolved value
 * says "no".
 *
 * Extends RuntimeException (not a bespoke base) so any existing
 * catch(RuntimeException) / catch(\Exception) block still handles it safely,
 * while a dedicated catch(EntitlementDeniedException) site can special-case
 * it (e.g. to render an upgrade prompt naming the specific feature/limit).
 */
class EntitlementDeniedException extends RuntimeException
{
    /**
     * 🏷️ The feature key that was denied (e.g. 'sso.enterprise_connections').
     *
     * @var string
     */
    private string $featureKey;

    /**
     * 🌐 The suggested HTTP status code a caller should surface for this
     * denial. Defaults to 402 (Payment Required) — the correct semantic for
     * "this is a paid feature you're not entitled to" — but a caller MAY
     * construct this with 403 (Forbidden) instead for an ops-kill-switch
     * denial (money isn't the blocker, the feature is simply switched off).
     *
     * @var int
     */
    private int $httpStatusCode;

    /**
     * 🏗️ Constructor
     *
     * @param string          $featureKey     The denied feature key
     * @param string          $message        Human-readable denial reason
     * @param int             $httpStatusCode Suggested HTTP status (default 402)
     * @param \Throwable|null $previous       Previous throwable for chaining
     */
    public function __construct(
        string $featureKey,
        string $message,
        int $httpStatusCode = 402,
        ?\Throwable $previous = null
    ) {
        $this->featureKey = $featureKey;
        $this->httpStatusCode = $httpStatusCode;

        parent::__construct($message, 0, $previous);
    }

    /**
     * 🏷️ Get the feature key that was denied.
     *
     * @return string
     */
    public function getFeatureKey(): string
    {
        return $this->featureKey;
    }

    /**
     * 🌐 Get the suggested HTTP status code for this denial.
     *
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}

// ✅ EntitlementDeniedException loaded successfully
