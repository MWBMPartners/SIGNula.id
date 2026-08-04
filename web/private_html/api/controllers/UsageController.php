<?php
/**
 * ============================================================================
 * 📊 SIGNula - Usage Tracking & Billing API Controller
 * ============================================================================
 *
 * Handles all usage tracking and billing-related API endpoints.
 * Interfaces with UsageTracker and UsageBillingService static classes
 * to record, retrieve, and manage usage-based billing data.
 *
 * Endpoints:
 * - POST   /api/v1/usage/record            - Record a single usage event
 * - POST   /api/v1/usage/batch             - Record multiple usage events
 * - GET    /api/v1/usage/current            - Get current period usage
 * - GET    /api/v1/usage/history            - Get historical usage summaries
 * - GET    /api/v1/usage/projected-cost     - Get projected billing cost
 * - GET    /api/v1/usage/billing-summaries  - Get billing summaries
 * - GET    /api/v1/usage/metrics            - Get available usage metrics
 * - PUT    /api/v1/usage/billing-mode       - Change billing mode
 *
 * @package    SIGNula
 * @subpackage API
 * @version    1.0.0
 * @since      2.7.0-beta
 * @author     MWBM Partners Ltd
 * @copyright  2025-2026 MWBM Partners Ltd
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚫 Prevent direct access — all files must be loaded via the application bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Class UsageController
 *
 * Manages usage tracking and billing API endpoints.
 * Partners submit usage via API key auth; end-users query their own
 * usage and billing data via session or bearer token auth.
 *
 * @package    SIGNula
 * @subpackage API\Controllers
 */
class UsageController extends BaseController
{
    // ========================================================================
    // 📝 POST /api/v1/usage/record — Record a Single Usage Event
    // ========================================================================

    /**
     * 📝 POST /api/v1/usage/record
     *
     * Records a single usage event for a user. This endpoint is intended
     * for partner services submitting usage data on behalf of their users.
     *
     * Requires API key authentication (partner context).
     *
     * Request Body:
     * {
     *   "user_id":     int,           // 🆔 The SIGNula user ID
     *   "metric_code": string,        // 📏 The usage metric identifier (e.g., "api_calls")
     *   "quantity":    float,         // 🔢 The quantity to record
     *   "metadata":    object|null    // 📦 Optional metadata (key-value pairs)
     * }
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageTracker::recordUsage()
     * @link https://www.php.net/manual/en/function.json-decode.php
     */
    public function recordUsage(array $params): void
    {
        // 🔑 Require API key authentication — only partners can submit usage.
        // Returns the AUTHENTICATED PARTNER's own partnerID (never trust the
        // request body for this) — see requireApiKeyAuth() below.
        $partnerID = $this->requireApiKeyAuth();

        // ✅ Validate the incoming request body
        $data = $this->validate([
            'user_id'     => 'required|integer',
            'metric_code' => 'required|string|min:1|max:100',
            'quantity'    => 'required|numeric|min:0',
        ]);

        $userID = (int) $data['user_id'];

        // 🛡️ CAP-API Bucket B (#2) — cross-tenant write guard (HIGH).
        // A valid API key only proves WHICH partner is calling — it says
        // NOTHING about whether that partner is entitled to bill/record
        // usage against the caller-supplied `user_id`. Without this check,
        // any partner's API key could submit usage events (and therefore
        // pollute billing) for ANY SIGNula user, not just their own.
        // This mirrors the SAME ownership check changeBillingMode() already
        // performs (~line 582) — there it verifies the SESSION user owns the
        // subscription; here we verify the AUTHENTICATED PARTNER owns (i.e.
        // has a tblSubscriptions relationship with) the target user.
        // @see self::verifyPartnerOwnsUser()
        // @see https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/05-Authorization_Testing/04-Testing_for_Insecure_Direct_Object_References
        if (!$this->verifyPartnerOwnsUser($partnerID, $userID)) {
            // 📊 Log the unauthorised attempt for security auditing
            ActivityLogger::log(
                $userID,
                'usage_record_denied',
                'failed',
                'Partner #' . $partnerID . ' attempted to record usage for user #' . $userID
                    . ' without an entitlement relationship'
            );

            $this->forbidden('This API key is not entitled to record usage for the specified user');
            return;
        }

        // 📦 Extract optional metadata from raw input (validate doesn't handle object type natively)
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $metadata = null;

        if (isset($rawInput['metadata'])) {
            // 🛡️ Ensure metadata is an associative array/object, not a scalar
            if (!is_array($rawInput['metadata'])) {
                $this->error('metadata must be an object or null', Response::HTTP_BAD_REQUEST);
                return;
            }
            $metadata = $rawInput['metadata'];
        }

        try {
            // 💾 Record the usage event via UsageTracker
            // 🐛 Fixed alongside the Bucket B ownership check: UsageTracker::
            // recordUsage()'s 4th positional parameter is `?int $partnerID`,
            // NOT metadata (that's the 5th) — the previous call here passed
            // $metadata into the $partnerID slot, which would throw a
            // TypeError for any request that actually supplied metadata (an
            // array is not an int|null) and, when metadata was omitted,
            // silently recorded every event as partnerID=null (no partner
            // attribution at all). Passing the authenticated $partnerID here
            // both fixes the type mismatch and correctly attributes the
            // usage record to the submitting partner for billing/reporting.
            $recordId = UsageTracker::recordUsage(
                $userID,
                $data['metric_code'],
                (float) $data['quantity'],
                $partnerID,
                $metadata
            );

            // 📊 Log the activity for audit trail
            ActivityLogger::log(
                (int) $data['user_id'],
                'usage_recorded',
                'success',
                'Usage recorded via API: ' . $data['metric_code'] . ' (' . $data['quantity'] . ')'
            );

            // ✅ Return the created record ID
            $this->created([
                'record_id'   => $recordId,
                'user_id'     => (int) $data['user_id'],
                'metric_code' => $data['metric_code'],
                'quantity'    => (float) $data['quantity'],
            ], 'Usage recorded successfully');

        } catch (Exception $e) {
            // 🚨 Log the error and return a generic server error
            ErrorLogger::log($e);
            $this->error('Failed to record usage', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 📦 POST /api/v1/usage/batch — Record Multiple Usage Events
    // ========================================================================

    /**
     * 📦 POST /api/v1/usage/batch
     *
     * Records multiple usage events in a single request for efficiency.
     * Partners can batch together several metric recordings for one user.
     *
     * Requires API key authentication (partner context).
     *
     * Request Body:
     * {
     *   "user_id": int,
     *   "items": [
     *     {
     *       "metric_code": string,
     *       "quantity":    float,
     *       "metadata":    object|null
     *     },
     *     ...
     *   ]
     * }
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageTracker::recordBatchUsage()
     */
    public function recordBatchUsage(array $params): void
    {
        // 🔑 Require API key authentication — only partners can submit usage.
        // Returns the AUTHENTICATED PARTNER's own partnerID (never trust the
        // request body for this) — see requireApiKeyAuth() below.
        $partnerID = $this->requireApiKeyAuth();

        // ✅ Validate top-level fields
        $data = $this->validate([
            'user_id' => 'required|integer',
            'items'   => 'required|array',
        ]);

        $userID = (int) $data['user_id'];

        // 🛡️ CAP-API Bucket B (#2) — cross-tenant write guard (HIGH).
        // Same rationale as recordUsage() above: a valid API key only
        // proves WHICH partner is calling, not that the partner is
        // entitled to record usage against this particular user. Mirrors
        // the ownership check changeBillingMode() already performs (~line
        // 582) — see verifyPartnerOwnsUser() for the query.
        if (!$this->verifyPartnerOwnsUser($partnerID, $userID)) {
            // 📊 Log the unauthorised attempt for security auditing
            ActivityLogger::log(
                $userID,
                'usage_batch_record_denied',
                'failed',
                'Partner #' . $partnerID . ' attempted to record batch usage for user #' . $userID
                    . ' without an entitlement relationship'
            );

            $this->forbidden('This API key is not entitled to record usage for the specified user');
            return;
        }

        // 📋 Parse raw input to get the full items array with metadata
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $items = $rawInput['items'] ?? [];

        // 🔍 Validate each item in the batch
        if (empty($items)) {
            $this->error('items array must not be empty', Response::HTTP_BAD_REQUEST);
            return;
        }

        // 🛡️ Enforce a reasonable batch size limit to prevent abuse
        $maxBatchSize = (int) getSetting('usage.max_batch_size', 100);
        if (count($items) > $maxBatchSize) {
            $this->error(
                'Batch size exceeds maximum of ' . $maxBatchSize . ' items',
                Response::HTTP_BAD_REQUEST
            );
            return;
        }

        // ✅ Validate each individual item in the batch
        $validatedItems = [];
        $errors = [];

        foreach ($items as $index => $item) {
            // 📏 Ensure each item has the required fields
            if (empty($item['metric_code']) || !is_string($item['metric_code'])) {
                $errors[] = "Item [$index]: metric_code is required and must be a string";
                continue;
            }

            if (!isset($item['quantity']) || !is_numeric($item['quantity'])) {
                $errors[] = "Item [$index]: quantity is required and must be numeric";
                continue;
            }

            if ((float) $item['quantity'] < 0) {
                $errors[] = "Item [$index]: quantity must be non-negative";
                continue;
            }

            // 📦 Validate metadata if provided
            if (isset($item['metadata']) && !is_array($item['metadata'])) {
                $errors[] = "Item [$index]: metadata must be an object or null";
                continue;
            }

            // ✅ Item passes validation — add to the list
            $validatedItems[] = [
                'metric_code' => $item['metric_code'],
                'quantity'    => (float) $item['quantity'],
                'metadata'    => $item['metadata'] ?? null,
            ];
        }

        // ❌ If any items failed validation, return all errors at once
        if (!empty($errors)) {
            Response::validationError('Batch validation failed', $errors);
            return;
        }

        try {
            // 💾 Record the batch via UsageTracker
            // 🐛 Also pass the authenticated $partnerID (previously omitted
            // entirely, defaulting to null) so every record in the batch is
            // correctly attributed to the submitting partner for
            // billing/reporting — same fix as recordUsage() above.
            $result = UsageTracker::recordBatchUsage(
                $userID,
                $validatedItems,
                $partnerID
            );

            // 📊 Log the batch activity
            ActivityLogger::log(
                $userID,
                'usage_batch_recorded',
                'success',
                'Batch usage recorded via API: ' . count($validatedItems) . ' items'
            );

            // ✅ Return summary of the batch operation
            $this->created([
                'user_id'        => $userID,
                'items_recorded' => count($validatedItems),
                'result'         => $result,
            ], 'Batch usage recorded successfully');

        } catch (Exception $e) {
            // 🚨 Log the error and return a generic server error
            ErrorLogger::log($e);
            $this->error('Failed to record batch usage', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 📈 GET /api/v1/usage/current — Get Current Period Usage
    // ========================================================================

    /**
     * 📈 GET /api/v1/usage/current
     *
     * Returns usage data for the current billing period for the
     * authenticated user. Shows all tracked metrics and their totals.
     *
     * Requires user authentication (session or bearer token).
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageTracker::getCurrentPeriodUsage()
     */
    public function getCurrentUsage(array $params): void
    {
        // 🔒 Require user authentication — users can only view their own usage
        $user = $this->requireAuth();
        $userID = (int) $user['userID'];

        try {
            // 📊 Fetch current period usage from UsageTracker
            $usage = UsageTracker::getCurrentPeriodUsage($userID);

            // 📊 Log the access for audit purposes
            ActivityLogger::log(
                $userID,
                'usage_viewed',
                'success',
                'Current usage data retrieved via API'
            );

            // ✅ Return the usage data
            $this->success([
                'user_id' => $userID,
                'usage'   => $usage,
            ], 'Current usage retrieved successfully');

        } catch (Exception $e) {
            // 🚨 Log and return server error
            ErrorLogger::log($e);
            $this->error('Failed to retrieve current usage', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 📜 GET /api/v1/usage/history — Get Historical Usage Summaries
    // ========================================================================

    /**
     * 📜 GET /api/v1/usage/history
     *
     * Returns historical usage summaries for the authenticated user.
     * Supports pagination via query parameters.
     *
     * Requires user authentication (session or bearer token).
     *
     * Query Parameters:
     * - limit  (int, default: 12)  — Number of periods to return
     * - offset (int, default: 0)   — Number of periods to skip
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageTracker::getUsageHistory()
     */
    public function getUsageHistory(array $params): void
    {
        // 🔒 Require user authentication
        $user = $this->requireAuth();
        $userID = (int) $user['userID'];

        // 📋 Parse and sanitise pagination parameters from query string
        // @see https://www.php.net/manual/en/function.max.php
        $limit  = max(1, min(100, (int) ($this->query('limit', 12))));
        $offset = max(0, (int) ($this->query('offset', 0)));

        try {
            // 📊 Fetch historical usage from UsageTracker
            $history = UsageTracker::getUsageHistory($userID, $limit, $offset);

            // 📊 Log the access
            ActivityLogger::log(
                $userID,
                'usage_history_viewed',
                'success',
                'Usage history retrieved via API (limit: ' . $limit . ', offset: ' . $offset . ')'
            );

            // ✅ Return the historical data with pagination info
            $this->success([
                'user_id' => $userID,
                'history' => $history,
                'limit'   => $limit,
                'offset'  => $offset,
            ], 'Usage history retrieved successfully');

        } catch (Exception $e) {
            // 🚨 Log and return server error
            ErrorLogger::log($e);
            $this->error('Failed to retrieve usage history', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 💰 GET /api/v1/usage/projected-cost — Get Projected Billing Cost
    // ========================================================================

    /**
     * 💰 GET /api/v1/usage/projected-cost
     *
     * Returns the projected cost for the current billing period based on
     * current usage patterns. Uses the user's active subscription to
     * determine applicable rates and tiers.
     *
     * Requires user authentication (session or bearer token).
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageBillingService::getProjectedCost()
     */
    public function getProjectedCost(array $params): void
    {
        // 🔒 Require user authentication
        $user = $this->requireAuth();
        $userID = (int) $user['userID'];

        try {
            // 🔍 Look up the user's active subscription
            $subscription = $this->getActiveSubscription($userID);

            if ($subscription === null) {
                $this->error(
                    'No active subscription found for this user',
                    Response::HTTP_NOT_FOUND
                );
                return;
            }

            // 💰 Calculate projected cost via UsageBillingService
            $projectedCost = UsageBillingService::getProjectedCost(
                (int) $subscription['subscriptionID']
            );

            // 📊 Log the access
            ActivityLogger::log(
                $userID,
                'projected_cost_viewed',
                'success',
                'Projected cost retrieved via API for subscription #' . $subscription['subscriptionID']
            );

            // ✅ Return the projected cost data
            $this->success([
                'user_id'         => $userID,
                'subscription_id' => (int) $subscription['subscriptionID'],
                'projected_cost'  => $projectedCost,
            ], 'Projected cost retrieved successfully');

        } catch (Exception $e) {
            // 🚨 Log and return server error
            ErrorLogger::log($e);
            $this->error('Failed to retrieve projected cost', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 🧾 GET /api/v1/usage/billing-summaries — Get Billing Summaries
    // ========================================================================

    /**
     * 🧾 GET /api/v1/usage/billing-summaries
     *
     * Returns billing summaries for the authenticated user across
     * previous billing periods. Supports pagination.
     *
     * Requires user authentication (session or bearer token).
     *
     * Query Parameters:
     * - limit  (int, default: 12)  — Number of summaries to return
     * - offset (int, default: 0)   — Number of summaries to skip
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageBillingService::getSummariesForUser()
     */
    public function getBillingSummaries(array $params): void
    {
        // 🔒 Require user authentication
        $user = $this->requireAuth();
        $userID = (int) $user['userID'];

        // 📋 Parse and sanitise pagination parameters
        $limit  = max(1, min(100, (int) ($this->query('limit', 12))));
        $offset = max(0, (int) ($this->query('offset', 0)));

        try {
            // 🧾 Fetch billing summaries via UsageBillingService
            $summaries = UsageBillingService::getSummariesForUser(
                $userID,
                $limit,
                $offset
            );

            // 📊 Log the access
            ActivityLogger::log(
                $userID,
                'billing_summaries_viewed',
                'success',
                'Billing summaries retrieved via API (limit: ' . $limit . ', offset: ' . $offset . ')'
            );

            // ✅ Return the billing summaries
            $this->success([
                'user_id'   => $userID,
                'summaries' => $summaries,
                'limit'     => $limit,
                'offset'    => $offset,
            ], 'Billing summaries retrieved successfully');

        } catch (Exception $e) {
            // 🚨 Log and return server error
            ErrorLogger::log($e);
            $this->error('Failed to retrieve billing summaries', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 📏 GET /api/v1/usage/metrics — Get Available Usage Metrics
    // ========================================================================

    /**
     * 📏 GET /api/v1/usage/metrics
     *
     * Returns the list of available usage metrics that can be tracked.
     * This is a public endpoint — no authentication required.
     * Useful for partners integrating with the usage tracking system.
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageTracker::getMetrics()
     */
    public function getMetrics(array $params): void
    {
        // 🌐 Public endpoint — no authentication required

        try {
            // 📏 Fetch available metrics from UsageTracker
            $metrics = UsageTracker::getMetrics();

            // ✅ Return the metrics list
            $this->success([
                'metrics' => $metrics,
            ], 'Usage metrics retrieved successfully');

        } catch (Exception $e) {
            // 🚨 Log and return server error
            ErrorLogger::log($e);
            $this->error('Failed to retrieve usage metrics', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 🔄 PUT /api/v1/usage/billing-mode — Change Billing Mode
    // ========================================================================

    /**
     * 🔄 PUT /api/v1/usage/billing-mode
     *
     * Changes the billing mode for a user's subscription. Validates that
     * the authenticated user owns the specified subscription before
     * applying any changes.
     *
     * Requires user authentication (session or bearer token).
     *
     * Request Body:
     * {
     *   "subscription_id": int,                    // 🆔 The subscription to modify
     *   "mode":            "fixed"|"usage"|"hybrid" // 📊 The new billing mode
     * }
     *
     * @param array $params Route parameters from the Router
     * @return void (exits via Response)
     *
     * @see UsageBillingService::changeBillingMode()
     */
    public function changeBillingMode(array $params): void
    {
        // 🔒 Require user authentication
        $user = $this->requireAuth();
        $userID = (int) $user['userID'];

        // ✅ Validate the incoming request body
        $data = $this->validate([
            'subscription_id' => 'required|integer',
            'mode'            => 'required|string|in:fixed,usage,hybrid',
        ]);

        $subscriptionID = (int) $data['subscription_id'];
        $newMode        = $data['mode'];

        try {
            // 🔍 Verify the user owns this subscription — prevents IDOR attacks
            // @see https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/05-Authorization_Testing/04-Testing_for_Insecure_Direct_Object_References
            $subscription = $this->getSubscriptionById($subscriptionID);

            if ($subscription === null) {
                $this->notFound('Subscription not found');
                return;
            }

            // 🛡️ Ensure the authenticated user owns this subscription
            if ((int) $subscription['userID'] !== $userID) {
                // 📊 Log the unauthorised attempt for security auditing
                ActivityLogger::log(
                    $userID,
                    'billing_mode_change_denied',
                    'failed',
                    'Attempted to change billing mode for subscription #' . $subscriptionID
                        . ' owned by user #' . $subscription['userID']
                );

                $this->forbidden('You do not own this subscription');
                return;
            }

            // 🔄 Change the billing mode via UsageBillingService
            $result = UsageBillingService::changeBillingMode(
                $subscriptionID,
                $newMode
            );

            // 📊 Log the successful change
            ActivityLogger::log(
                $userID,
                'billing_mode_changed',
                'success',
                'Billing mode changed to "' . $newMode . '" for subscription #' . $subscriptionID
            );

            // ✅ Return the updated billing mode
            $this->success([
                'subscription_id' => $subscriptionID,
                'mode'            => $newMode,
                'result'          => $result,
            ], 'Billing mode updated successfully');

        } catch (Exception $e) {
            // 🚨 Log and return server error
            ErrorLogger::log($e);
            $this->error('Failed to change billing mode', Response::HTTP_INTERNAL_ERROR);
        }
    }

    // ========================================================================
    // 🔧 Private Helper Methods
    // ========================================================================

    /**
     * 🔑 Require API key authentication
     *
     * Validates that the request includes a valid API key via
     * the X-API-Key header or api_key query parameter.
     * Exits with 401 Unauthorized if no valid key is found.
     *
     * 🛡️ CAP-API Bucket B (#2): now RETURNS the authenticated partner's
     * partnerID (resolved server-side from the validated key — never from
     * client input) so callers can perform the partner↔user entitlement
     * check before trusting a caller-supplied `user_id`.
     * @see self::verifyPartnerOwnsUser()
     *
     * @return int The authenticated partner's partnerID
     *             (exits via Response before returning on any failure)
     *
     * @see https://www.php.net/manual/en/function.getallheaders.php
     */
    private function requireApiKeyAuth(): int
    {
        // 🔍 Check for API key in request headers first (preferred method)
        $headers = getallheaders();
        $apiKey  = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;

        // 🔍 Fall back to query parameter if header not present
        if ($apiKey === null) {
            $apiKey = $_GET['api_key'] ?? null;
        }

        // ❌ No API key provided at all
        if ($apiKey === null || trim($apiKey) === '') {
            $this->unauthorized('API key is required for this endpoint');
            return 0; // ⛔ Unreachable — unauthorized() exits via Response::send().
        }

        // 🔒 Validate the API key against the database
        // @see https://www.php.net/manual/en/function.hash.php
        try {
            $query = "
                SELECT k.keyID, k.partnerID, k.isActive, k.expiresAt
                FROM tblAPIKeys k
                WHERE k.keyHash = ?
                  AND k.isActive = 1
                  AND (k.expiresAt IS NULL OR k.expiresAt > NOW())
            ";

            // 🔐 Hash the API key before comparison — keys are stored hashed (SHA-256)
            // @see web/private_html/api/APIKeyManager.php — uses hash('sha256', $apiKey)
            $keyHash = hash('sha256', $apiKey);
            $result = Database::query($query, [$keyHash], 's');
            $keyData = $result ? $result->fetch_assoc() : null;

            if ($keyData === null) {
                // 📊 Log the failed authentication attempt
                ActivityLogger::logGuest(
                    'api_key_auth_failed',
                    'failed',
                    'Invalid or expired API key used on usage endpoint'
                );

                $this->unauthorized('Invalid or expired API key');
                return 0; // ⛔ Unreachable — unauthorized() exits via Response::send().
            }

            // ✅ Return the AUTHENTICATED partner's own ID — this is the ONLY
            // trustworthy source of "which partner is calling"; it is NEVER
            // taken from request input.
            return (int) $keyData['partnerID'];

        } catch (Exception $e) {
            // 🚨 Log the error but don't expose internals
            ErrorLogger::log($e);
            $this->error('Authentication service unavailable', Response::HTTP_INTERNAL_ERROR);
            return 0; // ⛔ Unreachable — error() exits via Response::send().
        }
    }

    /**
     * 🛡️ Verify a partner is entitled to record/bill usage for a user (CAP-API Bucket B, #2)
     *
     * A partner is considered entitled to a user when a tblSubscriptions row
     * links that userID to that partnerID — the SAME userID/partnerID
     * relationship Entitlements::resolveActiveSubscription() and
     * UsageTracker's own billing-period lookups already treat as the
     * canonical partner↔user linkage in this schema (tblSubscriptions.userID
     * = "account owner", tblSubscriptions.partnerID = "partner organisation,
     * if applicable" — see _database/signula_complete_install_v2.5.0.sql).
     *
     * Deliberately fail-CLOSED (returns false on any error) — unlike the
     * dormant, fail-OPEN Entitlements resolver, this is a hard security
     * boundary: an ambiguous/errored lookup must NEVER be treated as
     * "entitled", or a DB hiccup would silently reopen the cross-tenant
     * write hole this check exists to close.
     *
     * @param int $partnerID The AUTHENTICATED partner's ID (from requireApiKeyAuth())
     * @param int $userID    The target SIGNula user ID from the request body
     * @return bool True if this partner has a subscription relationship with this user
     */
    private function verifyPartnerOwnsUser(int $partnerID, int $userID): bool
    {
        try {
            $query = "
                SELECT COUNT(*) AS relationshipCount
                FROM tblSubscriptions
                WHERE userID = ?
                  AND partnerID = ?
            ";

            $result = Database::query($query, [$userID, $partnerID], 'ii');
            $row = $result ? $result->fetch_assoc() : null;

            return $row !== null && (int) $row['relationshipCount'] > 0;

        } catch (Exception $e) {
            // 🚨 Fail CLOSED — log the error but treat it as "not entitled".
            ErrorLogger::log($e);
            return false;
        }
    }

    /**
     * 🔍 Get the user's active subscription
     *
     * Retrieves the most recent active subscription for the given user.
     * Used to determine billing context for projected cost calculations.
     *
     * @param int $userID The user ID to look up
     * @return array|null Subscription data or null if none found
     */
    private function getActiveSubscription(int $userID): ?array
    {
        try {
            $query = "
                SELECT *
                FROM tblSubscriptions
                WHERE userID = ?
                  AND status = 'active'
                ORDER BY createdAt DESC
                LIMIT 1
            ";

            $result = Database::query($query, [$userID], 'i');

            if ($result && $row = $result->fetch_assoc()) {
                return $row;
            }

            return null;

        } catch (Exception $e) {
            // 🚨 Log and return null — caller handles the missing subscription
            ErrorLogger::log($e);
            return null;
        }
    }

    /**
     * 🔍 Get subscription by ID
     *
     * Retrieves a subscription record by its primary key.
     * Used to verify ownership before modifying billing settings.
     *
     * @param int $subscriptionID The subscription ID to look up
     * @return array|null Subscription data or null if not found
     */
    private function getSubscriptionById(int $subscriptionID): ?array
    {
        try {
            $query = "
                SELECT *
                FROM tblSubscriptions
                WHERE subscriptionID = ?
            ";

            $result = Database::query($query, [$subscriptionID], 'i');

            if ($result && $row = $result->fetch_assoc()) {
                return $row;
            }

            return null;

        } catch (Exception $e) {
            // 🚨 Log and return null — caller handles the missing subscription
            ErrorLogger::log($e);
            return null;
        }
    }
}

// ✅ UsageController loaded successfully
