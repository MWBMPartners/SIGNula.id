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
        // 🔑 Require API key authentication — only partners can submit usage
        $this->requireApiKeyAuth();

        // ✅ Validate the incoming request body
        $data = $this->validate([
            'user_id'     => 'required|integer',
            'metric_code' => 'required|string|min:1|max:100',
            'quantity'    => 'required|numeric|min:0',
        ]);

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
            $recordId = UsageTracker::recordUsage(
                (int) $data['user_id'],
                $data['metric_code'],
                (float) $data['quantity'],
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
        // 🔑 Require API key authentication — only partners can submit usage
        $this->requireApiKeyAuth();

        // ✅ Validate top-level fields
        $data = $this->validate([
            'user_id' => 'required|integer',
            'items'   => 'required|array',
        ]);

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
            $result = UsageTracker::recordBatchUsage(
                (int) $data['user_id'],
                $validatedItems
            );

            // 📊 Log the batch activity
            ActivityLogger::log(
                (int) $data['user_id'],
                'usage_batch_recorded',
                'success',
                'Batch usage recorded via API: ' . count($validatedItems) . ' items'
            );

            // ✅ Return summary of the batch operation
            $this->created([
                'user_id'        => (int) $data['user_id'],
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
     * @return void (exits via Response on failure)
     *
     * @see https://www.php.net/manual/en/function.getallheaders.php
     */
    private function requireApiKeyAuth(): void
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
            return;
        }

        // 🔒 Validate the API key against the database
        // @see https://www.php.net/manual/en/function.hash.php
        try {
            $query = "
                SELECT k.keyID, k.partnerID, k.isActive, k.expiresAt
                FROM tblAPIKeys k
                WHERE k.apiKey = ?
                  AND k.isActive = 1
                  AND (k.expiresAt IS NULL OR k.expiresAt > NOW())
            ";

            $result = Database::query($query, [$apiKey], 's');
            $keyData = $result ? $result->fetch_assoc() : null;

            if ($keyData === null) {
                // 📊 Log the failed authentication attempt
                ActivityLogger::logGuest(
                    'api_key_auth_failed',
                    'failed',
                    'Invalid or expired API key used on usage endpoint'
                );

                $this->unauthorized('Invalid or expired API key');
                return;
            }

        } catch (Exception $e) {
            // 🚨 Log the error but don't expose internals
            ErrorLogger::log($e);
            $this->error('Authentication service unavailable', Response::HTTP_INTERNAL_ERROR);
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
