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
 * 📊 SIGNula - Usage Tracker
 * ============================================================================
 *
 * Purpose: Track, aggregate, and manage usage-based metering data for the
 *          hybrid billing system. Records individual usage events, aggregates
 *          them per billing period, and provides querying/management utilities.
 * PHP Version: 8.3+
 *
 * Architecture Overview:
 * Usage events are recorded in real-time to tblUsageRecords with automatic
 * billing period assignment derived from the user's subscription cycle.
 * At billing time, BillingScheduler aggregates these records via
 * getUsageForPeriod() and marks them as processed.
 *
 * Features:
 * - Real-time usage event recording with automatic period assignment
 * - Batch recording for high-throughput scenarios (single transaction)
 * - Per-metric aggregation using configurable aggregation types (sum/max/avg)
 * - Current and historical usage querying with pagination
 * - Metric CRUD management (global + partner-specific)
 * - Processed/unprocessed record lifecycle management
 * - Data retention and purging for GDPR/storage compliance
 * - Admin dashboard statistics
 *
 * Database Tables:
 * - tblUsageMetrics — metric definitions (what can be metered)
 * - tblUsageRecords — individual usage events (high-volume)
 * - tblSubscriptions — subscription context for billing period derivation
 *
 * Settings (from tblSettings):
 * - billing.usage.enabled             (master toggle for usage billing)
 * - billing.usage.tracking_enabled    (toggle for recording new events)
 * - billing.usage.batch_size          (max items per batch operation)
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/innodb-transaction-model.html (Transactions)
 * @see https://dev.mysql.com/doc/refman/8.0/en/group-by-functions.html (Aggregate functions)
 * @see https://www.php.net/manual/en/function.json-encode.php (JSON metadata)
 *
 * @package    SIGNula
 * @subpackage Payments
 * @version    1.0.0
 * @since      2.7.0-beta
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
 * 📊 Usage Tracker Class
 *
 * Static utility class for recording and querying usage-based metering data.
 * All methods are public static — no instantiation required.
 *
 * Usage events flow:
 *   1. recordUsage() / recordBatchUsage() — write events to tblUsageRecords
 *   2. getUsageForPeriod() — aggregate for billing calculation
 *   3. markRecordsProcessed() — flag records as billed
 *   4. purgeOldRecords() — clean up after retention period
 *
 * Usage:
 * ```php
 * // 📝 Record a single usage event
 * $result = UsageTracker::recordUsage(
 *     userID: 42,
 *     metricCode: 'api_calls',
 *     quantity: 1.0,
 *     metadata: ['endpoint' => '/api/v1/users', 'method' => 'GET']
 * );
 *
 * // 📦 Record multiple events in a batch
 * $result = UsageTracker::recordBatchUsage(42, [
 *     ['metricCode' => 'api_calls', 'quantity' => 1.0],
 *     ['metricCode' => 'storage_gb', 'quantity' => 0.025],
 * ]);
 *
 * // 📊 Get aggregated usage for current billing period
 * $usage = UsageTracker::getCurrentPeriodUsage(42);
 *
 * // ✅ Mark records as processed after billing
 * UsageTracker::markRecordsProcessed(42, '2026-03-01', '2026-03-31');
 * ```
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html
 */
class UsageTracker
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * @var array Valid aggregation types for tblUsageMetrics.aggregationType ENUM
     * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
     */
    public const VALID_AGGREGATION_TYPES = ['sum', 'max', 'avg'];

    /**
     * @var int Default batch size when not configured in tblSettings
     * Controls how many usage items can be recorded in a single batch call
     */
    public const DEFAULT_BATCH_SIZE = 100;

    /**
     * @var int Default retention period in days for processed usage records
     * Records older than this are eligible for purging via purgeOldRecords()
     */
    public const DEFAULT_RETENTION_DAYS = 365;

    // ========================================================================
    // 📝 USAGE RECORDING
    // ========================================================================

    /**
     * 📝 Record a single usage event
     *
     * Writes one usage record to tblUsageRecords with automatic billing period
     * assignment derived from the user's active subscription cycle. If no active
     * subscription exists, the current calendar month is used as a fallback.
     *
     * The metricCode is resolved to a metricID via tblUsageMetrics, checking
     * partner-specific metrics first then falling back to global metrics.
     *
     * @param int        $userID     The user generating this usage (FK to tblUsers)
     * @param string     $metricCode Machine-readable metric code (e.g. 'api_calls')
     * @param float      $quantity   Amount consumed (default: 1.0 for countable events)
     * @param int|null   $partnerID  Partner context (null = SIGNula direct usage)
     * @param array|null $metadata   Optional JSON-serialisable context data
     *                               (endpoint, resource ID, request IP, etc.)
     *
     * @return array{success: bool, recordID?: int, error?: string}
     *               On success: ['success' => true, 'recordID' => int]
     *               On failure: ['success' => false, 'error' => string]
     *
     * @see self::getMetricByCode() For metric resolution logic
     * @see self::getBillingPeriod() For billing period derivation
     * @see https://www.php.net/manual/en/function.json-encode.php
     */
    public static function recordUsage(
        int $userID,
        string $metricCode,
        float $quantity = 1.0,
        ?int $partnerID = null,
        ?array $metadata = null
    ): array {
        try {
            // 🔍 Check if usage tracking is enabled globally
            // This setting allows admins to pause tracking without disabling the
            // entire usage billing system (billing.usage.enabled controls billing)
            $trackingEnabled = getSetting('billing.usage.tracking_enabled');
            if ($trackingEnabled === '0' || $trackingEnabled === 'false') {
                return [
                    'success' => false,
                    'error'   => 'Usage tracking is currently disabled'
                ];
            }

            // 🔢 Validate quantity — must be a positive, non-zero value
            // @see https://www.php.net/manual/en/function.abs.php
            if ($quantity <= 0) {
                return [
                    'success' => false,
                    'error'   => 'Quantity must be a positive number greater than zero'
                ];
            }

            // 🏷️ Resolve the metric code to its internal metricID
            // Checks partner-specific metrics first, then falls back to global
            $metric = self::getMetricByCode($metricCode, $partnerID);
            if ($metric === null) {
                // ⚠️ Log a warning — this likely means the metric hasn't been set up
                ActivityLogger::log(
                    $userID,
                    'usage_metric_not_found',
                    'payment',
                    'warning',
                    'Attempted to record usage for unknown metric code: ' . $metricCode,
                    ['metricCode' => $metricCode, 'partnerID' => $partnerID]
                );

                return [
                    'success' => false,
                    'error'   => 'Unknown metric code: ' . $metricCode
                ];
            }

            // ❌ Check that the metric is active — inactive metrics cannot accept new records
            if (empty($metric['isActive'])) {
                return [
                    'success' => false,
                    'error'   => 'Metric "' . $metricCode . '" is currently inactive'
                ];
            }

            // 📅 Determine the billing period for this usage event
            // Derives start/end dates from the user's subscription cycle
            $period = self::getBillingPeriod($userID, $partnerID);

            // 📦 Encode metadata as JSON if provided
            // @see https://www.php.net/manual/en/function.json-encode.php
            $metadataJson = ($metadata !== null)
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            // 💾 Insert the usage record into tblUsageRecords
            // Uses prepared statement for SQL injection prevention
            // @see https://www.php.net/manual/en/mysqli.prepare.php
            $sql = "INSERT INTO tblUsageRecords
                        (userID, partnerID, metricID, quantity, recordedAt,
                         billingPeriodStart, billingPeriodEnd, metadata,
                         isProcessed, processedAt)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 0, NULL)";

            // 🔧 Build the parameter types string dynamically based on nullable fields
            // i = integer, d = double/decimal, s = string
            $params = [
                $userID,
                $partnerID,
                (int)$metric['metricID'],
                $quantity,
                $period['start'],
                $period['end'],
                $metadataJson
            ];
            $types = 'iiidss' . ($metadataJson !== null ? 's' : 's');

            Database::query($sql, $params, $types);

            // 🔑 Retrieve the auto-generated recordID for the caller
            $recordID = Database::getLastInsertId();

            // 📋 Log the usage recording activity for audit trail
            ActivityLogger::log(
                $userID,
                'usage_recorded',
                'payment',
                'info',
                'Usage recorded: ' . $quantity . ' ' . $metric['unitLabel']
                    . ' of ' . $metric['metricName'],
                [
                    'recordID'   => $recordID,
                    'metricCode' => $metricCode,
                    'metricID'   => (int)$metric['metricID'],
                    'quantity'   => $quantity,
                    'partnerID'  => $partnerID,
                    'period'     => $period
                ]
            );

            return [
                'success'  => true,
                'recordID' => $recordID
            ];

        } catch (\Exception $e) {
            // 🚨 Log the error for debugging — includes file and line for stack trace
            ErrorLogger::logError(
                'usage_recording_error',
                'Failed to record usage event: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                [
                    'userID'     => $userID,
                    'metricCode' => $metricCode,
                    'quantity'   => $quantity,
                    'partnerID'  => $partnerID
                ]
            );

            return [
                'success' => false,
                'error'   => 'An internal error occurred while recording usage'
            ];
        }
    }

    /**
     * 📦 Record multiple usage events in a single transaction
     *
     * Batch recording improves performance by wrapping multiple INSERT operations
     * in a single database transaction, reducing round-trips and ensuring atomicity.
     * If any individual item fails, the entire batch is rolled back.
     *
     * The batch size is limited by the 'billing.usage.batch_size' setting to
     * prevent excessively large transactions from blocking the database.
     *
     * @param int      $userID     The user generating this usage (FK to tblUsers)
     * @param array    $usageItems Array of usage items, each containing:
     *                             - 'metricCode' (string, required): Metric identifier
     *                             - 'quantity'   (float, optional):  Amount consumed (default: 1.0)
     *                             - 'metadata'   (array|null, optional): Context data
     * @param int|null $partnerID  Partner context (null = SIGNula direct usage)
     *
     * @return array{success: bool, recorded: int, failed: int, errors?: array}
     *               On success: ['success' => true, 'recorded' => int, 'failed' => 0]
     *               On partial/full failure: ['success' => false, 'recorded' => 0,
     *                                        'failed' => int, 'errors' => array]
     *
     * @see self::recordUsage() For single-event recording
     * @see https://dev.mysql.com/doc/refman/8.0/en/commit.html (Transaction control)
     */
    public static function recordBatchUsage(
        int $userID,
        array $usageItems,
        ?int $partnerID = null
    ): array {
        try {
            // 🔍 Check if usage tracking is enabled globally
            $trackingEnabled = getSetting('billing.usage.tracking_enabled');
            if ($trackingEnabled === '0' || $trackingEnabled === 'false') {
                return [
                    'success'  => false,
                    'recorded' => 0,
                    'failed'   => count($usageItems),
                    'errors'   => ['Usage tracking is currently disabled']
                ];
            }

            // 📋 Validate that the usage items array is not empty
            if (empty($usageItems)) {
                return [
                    'success'  => false,
                    'recorded' => 0,
                    'failed'   => 0,
                    'errors'   => ['No usage items provided']
                ];
            }

            // 🔢 Enforce the batch size limit from settings
            // Prevents excessively large transactions from blocking the DB
            $maxBatchSize = (int)(getSetting('billing.usage.batch_size') ?: self::DEFAULT_BATCH_SIZE);
            if (count($usageItems) > $maxBatchSize) {
                return [
                    'success'  => false,
                    'recorded' => 0,
                    'failed'   => count($usageItems),
                    'errors'   => ['Batch size exceeds maximum of ' . $maxBatchSize . ' items']
                ];
            }

            // 📅 Determine the billing period once for all items in this batch
            // All items share the same user and therefore the same subscription cycle
            $period = self::getBillingPeriod($userID, $partnerID);

            // 🔄 Begin a database transaction for atomicity
            // If any INSERT fails, all are rolled back to maintain data consistency
            // @see https://dev.mysql.com/doc/refman/8.0/en/commit.html
            Database::beginTransaction();

            $recorded = 0;
            $failed = 0;
            $errors = [];

            // 📝 Prepare the INSERT SQL once, execute per item
            $sql = "INSERT INTO tblUsageRecords
                        (userID, partnerID, metricID, quantity, recordedAt,
                         billingPeriodStart, billingPeriodEnd, metadata,
                         isProcessed, processedAt)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 0, NULL)";

            foreach ($usageItems as $index => $item) {
                // 🏷️ Extract and validate the metric code (required field)
                $metricCode = $item['metricCode'] ?? null;
                if (empty($metricCode)) {
                    $failed++;
                    $errors[] = 'Item #' . $index . ': Missing metricCode';
                    continue;
                }

                // 🔢 Extract quantity with a sensible default
                $quantity = (float)($item['quantity'] ?? 1.0);
                if ($quantity <= 0) {
                    $failed++;
                    $errors[] = 'Item #' . $index . ': Quantity must be positive';
                    continue;
                }

                // 🏷️ Resolve metric code to metricID
                $metric = self::getMetricByCode($metricCode, $partnerID);
                if ($metric === null) {
                    $failed++;
                    $errors[] = 'Item #' . $index . ': Unknown metric code "' . $metricCode . '"';
                    continue;
                }

                // ❌ Skip inactive metrics
                if (empty($metric['isActive'])) {
                    $failed++;
                    $errors[] = 'Item #' . $index . ': Metric "' . $metricCode . '" is inactive';
                    continue;
                }

                // 📦 Encode metadata if provided
                $itemMetadata = $item['metadata'] ?? null;
                $metadataJson = ($itemMetadata !== null)
                    ? json_encode($itemMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null;

                // 💾 Execute the INSERT for this item
                $params = [
                    $userID,
                    $partnerID,
                    (int)$metric['metricID'],
                    $quantity,
                    $period['start'],
                    $period['end'],
                    $metadataJson
                ];
                $types = 'iiidss' . ($metadataJson !== null ? 's' : 's');

                Database::query($sql, $params, $types);
                $recorded++;
            }

            // ❌ If any items failed validation, roll back the entire batch
            // This ensures atomicity — either all succeed or none do
            if ($failed > 0) {
                Database::rollback();

                // ⚠️ Log the partial failure for debugging
                ActivityLogger::log(
                    $userID,
                    'usage_batch_partial_failure',
                    'payment',
                    'warning',
                    'Batch usage recording rolled back: ' . $failed . ' of '
                        . count($usageItems) . ' items failed validation',
                    [
                        'partnerID'  => $partnerID,
                        'totalItems' => count($usageItems),
                        'failed'     => $failed,
                        'errors'     => $errors
                    ]
                );

                return [
                    'success'  => false,
                    'recorded' => 0,
                    'failed'   => $failed + $recorded,
                    'errors'   => $errors
                ];
            }

            // ✅ All items passed — commit the transaction
            Database::commit();

            // 📋 Log the successful batch recording
            ActivityLogger::log(
                $userID,
                'usage_batch_recorded',
                'payment',
                'info',
                'Batch usage recorded: ' . $recorded . ' events in a single transaction',
                [
                    'partnerID'  => $partnerID,
                    'recorded'   => $recorded,
                    'period'     => $period
                ]
            );

            return [
                'success'  => true,
                'recorded' => $recorded,
                'failed'   => 0
            ];

        } catch (\Exception $e) {
            // 🔄 Attempt to roll back if an exception occurred mid-transaction
            try {
                Database::rollback();
            } catch (\Exception $rollbackEx) {
                // 🚨 Rollback itself failed — log this secondary error
                ErrorLogger::logError(
                    'usage_batch_rollback_error',
                    'Failed to rollback batch usage transaction: ' . $rollbackEx->getMessage(),
                    $rollbackEx->getFile(),
                    $rollbackEx->getLine(),
                    ['originalError' => $e->getMessage()]
                );
            }

            // 🚨 Log the primary error
            ErrorLogger::logError(
                'usage_batch_error',
                'Failed to record batch usage: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                [
                    'userID'     => $userID,
                    'partnerID'  => $partnerID,
                    'itemCount'  => count($usageItems)
                ]
            );

            return [
                'success'  => false,
                'recorded' => 0,
                'failed'   => count($usageItems),
                'errors'   => ['An internal error occurred during batch recording']
            ];
        }
    }

    // ========================================================================
    // 📊 USAGE QUERYING
    // ========================================================================

    /**
     * 📊 Get aggregated usage for a specific billing period
     *
     * Aggregates all usage records for the given user and period, grouped by
     * metric. Each metric's aggregationType (sum, max, avg) determines how
     * the raw records are combined into a single figure.
     *
     * This is the primary method used by BillingScheduler when calculating
     * usage-based charges for invoice generation.
     *
     * @param int      $userID             The user to query (FK to tblUsers)
     * @param string   $billingPeriodStart Start date (inclusive, format: 'YYYY-MM-DD')
     * @param string   $billingPeriodEnd   End date (inclusive, format: 'YYYY-MM-DD')
     * @param int|null $partnerID          Partner context filter (null = all partners)
     * @param int|null $metricID           Optional: filter to a specific metric
     *
     * @return array Array of associative arrays, each containing:
     *               - metricID (int)
     *               - metricCode (string)
     *               - metricName (string)
     *               - unitLabel (string)
     *               - totalUsage (float) — aggregated per aggregationType
     *               - aggregationType (string) — 'sum', 'max', or 'avg'
     *               - recordCount (int) — number of raw records aggregated
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/group-by-functions.html
     */
    public static function getUsageForPeriod(
        int $userID,
        string $billingPeriodStart,
        string $billingPeriodEnd,
        ?int $partnerID = null,
        ?int $metricID = null
    ): array {
        try {
            // 🏗️ Build the aggregation query dynamically
            // We use a CASE expression to apply the correct aggregate function
            // based on each metric's aggregationType configuration.
            //
            // This approach avoids running three separate queries (one per
            // aggregation type) and instead handles all types in a single pass.
            //
            // @see https://dev.mysql.com/doc/refman/8.0/en/case.html
            $sql = "SELECT
                        m.metricID,
                        m.metricCode,
                        m.metricName,
                        m.unitLabel,
                        m.aggregationType,
                        CASE m.aggregationType
                            WHEN 'sum' THEN COALESCE(SUM(r.quantity), 0)
                            WHEN 'max' THEN COALESCE(MAX(r.quantity), 0)
                            WHEN 'avg' THEN COALESCE(AVG(r.quantity), 0)
                            ELSE COALESCE(SUM(r.quantity), 0)
                        END AS totalUsage,
                        COUNT(r.recordID) AS recordCount
                    FROM tblUsageMetrics m
                    LEFT JOIN tblUsageRecords r
                        ON r.metricID = m.metricID
                        AND r.userID = ?
                        AND r.billingPeriodStart = ?
                        AND r.billingPeriodEnd = ?";

            // 🔧 Build WHERE clauses and parameters dynamically
            $params = [$userID, $billingPeriodStart, $billingPeriodEnd];
            $types = 'iss';
            $whereClauses = ['m.isActive = 1'];

            // 🏢 Filter by partner if specified
            if ($partnerID !== null) {
                // Include partner-specific metrics AND global metrics (partnerID IS NULL)
                $sql .= " AND (r.partnerID = ? OR r.partnerID IS NULL)";
                $params[] = $partnerID;
                $types .= 'i';
                $whereClauses[] = '(m.partnerID = ' . intval($partnerID) . ' OR m.partnerID IS NULL)';
            }

            // 🔍 Filter by specific metric if requested
            if ($metricID !== null) {
                $whereClauses[] = 'm.metricID = ?';
                $params[] = $metricID;
                $types .= 'i';
            }

            // 📋 Append WHERE clauses
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(' AND ', $whereClauses);
            }

            // 📊 Group by metric and order by the configured sort order
            $sql .= " GROUP BY m.metricID, m.metricCode, m.metricName,
                               m.unitLabel, m.aggregationType
                      ORDER BY m.sortOrder ASC, m.metricName ASC";

            $results = Database::fetchAll($sql, $params, $types);

            // 🔢 Cast totalUsage to float for consistent return types
            // Database returns DECIMAL as string in PHP; callers expect float
            foreach ($results as &$row) {
                $row['totalUsage'] = (float)$row['totalUsage'];
                $row['recordCount'] = (int)$row['recordCount'];
                $row['metricID'] = (int)$row['metricID'];
            }
            unset($row); // 🧹 Break reference to prevent accidental mutation

            return $results;

        } catch (\Exception $e) {
            // 🚨 Log the error and return an empty array to avoid breaking callers
            ErrorLogger::logError(
                'usage_query_error',
                'Failed to get usage for period: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                [
                    'userID'             => $userID,
                    'billingPeriodStart' => $billingPeriodStart,
                    'billingPeriodEnd'   => $billingPeriodEnd,
                    'partnerID'          => $partnerID,
                    'metricID'           => $metricID
                ]
            );

            return [];
        }
    }

    /**
     * 📊 Get usage for the current billing period (convenience wrapper)
     *
     * Determines the user's current billing period from their subscription
     * and delegates to getUsageForPeriod(). This is the most common query
     * for displaying real-time usage in dashboards and account pages.
     *
     * @param int      $userID    The user to query (FK to tblUsers)
     * @param int|null $partnerID Partner context filter (null = all partners)
     *
     * @return array Aggregated usage per metric for the current period
     *               (same format as getUsageForPeriod())
     *
     * @see self::getUsageForPeriod() For return format details
     * @see self::getBillingPeriod() For period derivation logic
     */
    public static function getCurrentPeriodUsage(
        int $userID,
        ?int $partnerID = null
    ): array {
        // 📅 Derive the current billing period from the user's subscription
        $period = self::getBillingPeriod($userID, $partnerID);

        // 📊 Delegate to the full period query method
        return self::getUsageForPeriod(
            $userID,
            $period['start'],
            $period['end'],
            $partnerID
        );
    }

    /**
     * 📜 Get historical usage summaries grouped by billing period
     *
     * Returns a paginated list of billing periods with aggregated usage totals.
     * Each period includes the sum of all metric usage and a record count.
     * Useful for usage history pages and billing statement generation.
     *
     * @param int      $userID    The user to query (FK to tblUsers)
     * @param int      $limit     Maximum number of periods to return (default: 12)
     * @param int      $offset    Number of periods to skip for pagination (default: 0)
     * @param int|null $partnerID Partner context filter (null = all partners)
     *
     * @return array Array of associative arrays, each containing:
     *               - billingPeriodStart (string) — period start date
     *               - billingPeriodEnd (string) — period end date
     *               - totalRecords (int) — number of usage events in this period
     *               - totalQuantity (float) — sum of all quantities across all metrics
     *               - metricsUsed (int) — number of distinct metrics with usage
     *               - isProcessed (bool) — whether all records are marked processed
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html (LIMIT/OFFSET)
     */
    public static function getUsageHistory(
        int $userID,
        int $limit = 12,
        int $offset = 0,
        ?int $partnerID = null
    ): array {
        try {
            // 🏗️ Build query to aggregate usage per billing period
            // Groups by period start/end to create a summary per cycle
            $sql = "SELECT
                        r.billingPeriodStart,
                        r.billingPeriodEnd,
                        COUNT(r.recordID) AS totalRecords,
                        COALESCE(SUM(r.quantity), 0) AS totalQuantity,
                        COUNT(DISTINCT r.metricID) AS metricsUsed,
                        MIN(r.isProcessed) AS isFullyProcessed
                    FROM tblUsageRecords r
                    WHERE r.userID = ?";

            $params = [$userID];
            $types = 'i';

            // 🏢 Filter by partner if specified
            if ($partnerID !== null) {
                $sql .= " AND r.partnerID = ?";
                $params[] = $partnerID;
                $types .= 'i';
            }

            // 📊 Group by billing period and order most recent first
            $sql .= " GROUP BY r.billingPeriodStart, r.billingPeriodEnd
                      ORDER BY r.billingPeriodStart DESC
                      LIMIT ? OFFSET ?";

            $params[] = $limit;
            $params[] = $offset;
            $types .= 'ii';

            $results = Database::fetchAll($sql, $params, $types);

            // 🔢 Cast numeric fields for consistent return types
            foreach ($results as &$row) {
                $row['totalRecords'] = (int)$row['totalRecords'];
                $row['totalQuantity'] = (float)$row['totalQuantity'];
                $row['metricsUsed'] = (int)$row['metricsUsed'];
                // 📋 isFullyProcessed = 1 only if ALL records in the period are processed
                // MIN(isProcessed) returns 0 if any record is unprocessed
                $row['isProcessed'] = ((int)$row['isFullyProcessed'] === 1);
                unset($row['isFullyProcessed']);
            }
            unset($row); // 🧹 Break reference

            return $results;

        } catch (\Exception $e) {
            // 🚨 Log the error and return empty to avoid breaking the UI
            ErrorLogger::logError(
                'usage_history_error',
                'Failed to get usage history: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                [
                    'userID'    => $userID,
                    'limit'     => $limit,
                    'offset'    => $offset,
                    'partnerID' => $partnerID
                ]
            );

            return [];
        }
    }

    // ========================================================================
    // 🏷️ METRIC MANAGEMENT
    // ========================================================================

    /**
     * 🏷️ Get available usage metrics
     *
     * Returns all defined usage metrics, optionally filtered to only active ones
     * and/or a specific partner. When a partnerID is provided, both partner-specific
     * metrics AND global metrics (partnerID IS NULL) are returned, since partners
     * inherit all global metrics.
     *
     * @param bool     $activeOnly If true, only return metrics where isActive = 1 (default: true)
     * @param int|null $partnerID  If set, include partner-specific metrics for this partner
     *                             in addition to global metrics. If null, only global metrics.
     *
     * @return array Array of metric records from tblUsageMetrics
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
     */
    public static function getMetrics(
        bool $activeOnly = true,
        ?int $partnerID = null
    ): array {
        try {
            // 🏗️ Build the query with optional filters
            $sql = "SELECT * FROM tblUsageMetrics WHERE 1=1";
            $params = [];
            $types = '';

            // ✅ Filter by active status if requested
            if ($activeOnly) {
                $sql .= " AND isActive = 1";
            }

            // 🏢 Filter by partner scope
            if ($partnerID !== null) {
                // Include global metrics (partnerID IS NULL) plus partner-specific ones
                $sql .= " AND (partnerID IS NULL OR partnerID = ?)";
                $params[] = $partnerID;
                $types .= 'i';
            } else {
                // Only global metrics when no partner specified
                $sql .= " AND partnerID IS NULL";
            }

            // 📋 Order by sort order then name for consistent display
            $sql .= " ORDER BY sortOrder ASC, metricName ASC";

            return Database::fetchAll($sql, $params, $types);

        } catch (\Exception $e) {
            // 🚨 Log and return empty array
            ErrorLogger::logError(
                'usage_metrics_query_error',
                'Failed to get usage metrics: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['activeOnly' => $activeOnly, 'partnerID' => $partnerID]
            );

            return [];
        }
    }

    /**
     * 🔍 Get a single metric by its machine-readable code
     *
     * Resolution priority:
     *   1. Partner-specific metric (if partnerID is provided)
     *   2. Global metric (partnerID IS NULL)
     *
     * This priority ensures partners can override global metric definitions
     * with their own customised versions while still falling back to the
     * system-wide definition.
     *
     * @param string   $metricCode The machine-readable metric code (e.g. 'api_calls')
     * @param int|null $partnerID  Partner to check first (null = global only)
     *
     * @return array|null The metric record, or null if not found
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/select.html
     */
    public static function getMetricByCode(
        string $metricCode,
        ?int $partnerID = null
    ): ?array {
        try {
            // 🏢 Step 1: Check for a partner-specific metric first
            if ($partnerID !== null) {
                $partnerMetric = Database::fetchOne(
                    "SELECT * FROM tblUsageMetrics
                     WHERE metricCode = ? AND partnerID = ?",
                    [$metricCode, $partnerID],
                    'si'
                );

                // ✅ If a partner-specific metric exists, return it immediately
                if ($partnerMetric) {
                    return $partnerMetric;
                }
            }

            // 🌐 Step 2: Fall back to the global metric (partnerID IS NULL)
            $globalMetric = Database::fetchOne(
                "SELECT * FROM tblUsageMetrics
                 WHERE metricCode = ? AND partnerID IS NULL",
                [$metricCode],
                's'
            );

            return $globalMetric ?: null;

        } catch (\Exception $e) {
            // 🚨 Log and return null to avoid breaking callers
            ErrorLogger::logError(
                'usage_metric_lookup_error',
                'Failed to look up metric by code: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['metricCode' => $metricCode, 'partnerID' => $partnerID]
            );

            return null;
        }
    }

    /**
     * ➕ Create a new usage metric definition
     *
     * Adds a new metric to tblUsageMetrics that can then be used for tracking
     * usage events. Metrics can be global (partnerID = null) or partner-specific.
     *
     * The metricCode must be unique within its scope (global or per-partner),
     * enforced by the uq_metric_code_scope unique constraint in the database.
     *
     * @param string      $metricCode       Machine-readable code (e.g. 'api_calls', 'storage_gb')
     * @param string      $metricName       Human-readable display name
     * @param string      $unitLabel        Display unit (e.g. 'calls', 'GB', 'emails')
     * @param int         $billingGranularity Bill per N units (1 = per unit, 1000 = per thousand)
     * @param string      $aggregationType  How to aggregate: 'sum', 'max', or 'avg'
     * @param int|null    $partnerID        Partner scope (null = global metric)
     * @param string|null $description      Optional detailed description
     *
     * @return array{success: bool, metricID?: int, error?: string}
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/insert.html
     */
    public static function createMetric(
        string $metricCode,
        string $metricName,
        string $unitLabel,
        int $billingGranularity = 1,
        string $aggregationType = 'sum',
        ?int $partnerID = null,
        ?string $description = null
    ): array {
        try {
            // 🛡️ Validate aggregation type against allowed ENUM values
            if (!in_array($aggregationType, self::VALID_AGGREGATION_TYPES, true)) {
                return [
                    'success' => false,
                    'error'   => 'Invalid aggregation type: "' . $aggregationType
                                 . '". Allowed values: ' . implode(', ', self::VALID_AGGREGATION_TYPES)
                ];
            }

            // 🔢 Validate billing granularity — must be a positive integer
            if ($billingGranularity < 1) {
                return [
                    'success' => false,
                    'error'   => 'Billing granularity must be a positive integer (minimum: 1)'
                ];
            }

            // 🏷️ Validate metric code format — alphanumeric + underscores only
            // This ensures compatibility with API parameters and database lookups
            // @see https://www.php.net/manual/en/function.preg-match.php
            if (!preg_match('/^[a-z0-9_]+$/i', $metricCode)) {
                return [
                    'success' => false,
                    'error'   => 'Metric code must contain only alphanumeric characters and underscores'
                ];
            }

            // 🔍 Check for duplicate metric code within the same scope
            $existing = self::getMetricByCode($metricCode, $partnerID);
            if ($existing !== null) {
                // ⚠️ Check if it's in the exact same scope (not just a global fallback)
                $existingPartnerID = $existing['partnerID'] ?? null;
                if (
                    ($partnerID === null && $existingPartnerID === null) ||
                    ($partnerID !== null && $existingPartnerID !== null
                        && (int)$existingPartnerID === $partnerID)
                ) {
                    return [
                        'success' => false,
                        'error'   => 'A metric with code "' . $metricCode
                                     . '" already exists in this scope'
                    ];
                }
            }

            // 💾 Insert the new metric record
            $sql = "INSERT INTO tblUsageMetrics
                        (metricCode, metricName, metricDescription, unitLabel,
                         billingGranularity, aggregationType, partnerID,
                         isActive, sortOrder)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)";

            $params = [
                $metricCode,
                $metricName,
                $description,
                $unitLabel,
                $billingGranularity,
                $aggregationType,
                $partnerID
            ];
            $types = 'ssssis' . ($partnerID !== null ? 'i' : 'i');

            Database::query($sql, $params, $types);

            // 🔑 Retrieve the auto-generated metricID
            $metricID = Database::getLastInsertId();

            // 📋 Log the metric creation for audit trail
            ActivityLogger::log(
                null,
                'usage_metric_created',
                'payment',
                'info',
                'Usage metric created: ' . $metricName . ' (' . $metricCode . ')',
                [
                    'metricID'           => $metricID,
                    'metricCode'         => $metricCode,
                    'metricName'         => $metricName,
                    'unitLabel'          => $unitLabel,
                    'billingGranularity' => $billingGranularity,
                    'aggregationType'    => $aggregationType,
                    'partnerID'          => $partnerID
                ]
            );

            return [
                'success'  => true,
                'metricID' => $metricID
            ];

        } catch (\Exception $e) {
            // 🚨 Log the error — could be a duplicate key or other DB constraint
            ErrorLogger::logError(
                'usage_metric_create_error',
                'Failed to create usage metric: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                [
                    'metricCode'      => $metricCode,
                    'metricName'      => $metricName,
                    'aggregationType' => $aggregationType,
                    'partnerID'       => $partnerID
                ]
            );

            return [
                'success' => false,
                'error'   => 'An internal error occurred while creating the metric'
            ];
        }
    }

    /**
     * ✏️ Update an existing usage metric definition
     *
     * Updates the specified fields of an existing metric record. Only fields
     * present in $data are modified; omitted fields remain unchanged.
     *
     * Allowed fields: metricName, metricDescription, unitLabel,
     * billingGranularity, aggregationType, isActive, sortOrder
     *
     * The metricCode is intentionally NOT updatable to prevent breaking
     * existing API integrations and usage recording calls.
     *
     * @param int   $metricID The metric to update (PK in tblUsageMetrics)
     * @param array $data     Associative array of fields to update. Keys must be
     *                        from the allowed set listed above.
     *
     * @return array{success: bool, error?: string}
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
     */
    public static function updateMetric(int $metricID, array $data): array
    {
        try {
            // 📋 Define the allowed fields to prevent arbitrary column injection
            // metricCode is intentionally excluded — changing it would break integrations
            $allowedFields = [
                'metricName',
                'metricDescription',
                'unitLabel',
                'billingGranularity',
                'aggregationType',
                'isActive',
                'sortOrder'
            ];

            // 🔍 Filter $data to only include allowed fields
            $updateData = array_intersect_key($data, array_flip($allowedFields));
            if (empty($updateData)) {
                return [
                    'success' => false,
                    'error'   => 'No valid fields provided for update. Allowed: '
                                 . implode(', ', $allowedFields)
                ];
            }

            // 🛡️ Validate aggregationType if it's being changed
            if (isset($updateData['aggregationType'])) {
                if (!in_array($updateData['aggregationType'], self::VALID_AGGREGATION_TYPES, true)) {
                    return [
                        'success' => false,
                        'error'   => 'Invalid aggregation type: "'
                                     . $updateData['aggregationType'] . '"'
                    ];
                }
            }

            // 🛡️ Validate billingGranularity if provided
            if (isset($updateData['billingGranularity'])) {
                if ((int)$updateData['billingGranularity'] < 1) {
                    return [
                        'success' => false,
                        'error'   => 'Billing granularity must be a positive integer'
                    ];
                }
            }

            // 🏗️ Build the SET clause dynamically from the filtered data
            // @see https://dev.mysql.com/doc/refman/8.0/en/update.html
            $setClauses = [];
            $params = [];
            $types = '';

            foreach ($updateData as $field => $value) {
                $setClauses[] = '`' . $field . '` = ?';
                $params[] = $value;

                // 🔧 Determine the MySQLi type character for this field
                if (in_array($field, ['billingGranularity', 'isActive', 'sortOrder'], true)) {
                    $types .= 'i'; // integer fields
                } else {
                    $types .= 's'; // string fields
                }
            }

            // 📋 Add the WHERE clause parameter
            $params[] = $metricID;
            $types .= 'i';

            // 💾 Execute the UPDATE query
            $sql = "UPDATE tblUsageMetrics
                    SET " . implode(', ', $setClauses) . "
                    WHERE metricID = ?";

            Database::query($sql, $params, $types);

            // 📋 Log the update for audit trail
            ActivityLogger::log(
                null,
                'usage_metric_updated',
                'payment',
                'info',
                'Usage metric #' . $metricID . ' updated',
                [
                    'metricID'     => $metricID,
                    'updatedFields' => array_keys($updateData)
                ]
            );

            return ['success' => true];

        } catch (\Exception $e) {
            // 🚨 Log the error
            ErrorLogger::logError(
                'usage_metric_update_error',
                'Failed to update usage metric: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['metricID' => $metricID, 'data' => $data]
            );

            return [
                'success' => false,
                'error'   => 'An internal error occurred while updating the metric'
            ];
        }
    }

    /**
     * 🗑️ Soft-delete a usage metric
     *
     * Rather than physically removing the metric record (which would break
     * foreign key references from existing tblUsageRecords), this method
     * sets isActive = 0, effectively hiding the metric from new recordings
     * and listings while preserving historical data integrity.
     *
     * @param int $metricID The metric to deactivate (PK in tblUsageMetrics)
     *
     * @return array{success: bool, error?: string}
     *
     * @see self::updateMetric() Used internally to set isActive = 0
     */
    public static function deleteMetric(int $metricID): array
    {
        try {
            // 🗑️ Soft-delete by deactivating the metric
            $sql = "UPDATE tblUsageMetrics SET isActive = 0 WHERE metricID = ?";
            Database::query($sql, [$metricID], 'i');

            // 📋 Log the soft-deletion for audit trail
            ActivityLogger::log(
                null,
                'usage_metric_deleted',
                'payment',
                'info',
                'Usage metric #' . $metricID . ' soft-deleted (set isActive = 0)',
                ['metricID' => $metricID]
            );

            return ['success' => true];

        } catch (\Exception $e) {
            // 🚨 Log the error
            ErrorLogger::logError(
                'usage_metric_delete_error',
                'Failed to soft-delete usage metric: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['metricID' => $metricID]
            );

            return [
                'success' => false,
                'error'   => 'An internal error occurred while deleting the metric'
            ];
        }
    }

    // ========================================================================
    // 📅 BILLING PERIOD MANAGEMENT
    // ========================================================================

    /**
     * 📅 Determine the current billing period for a user
     *
     * Derives the billing period start and end dates from the user's active
     * subscription in tblSubscriptions. The period is calculated by working
     * backwards from the nextBillingDate using the billingCycle (monthly,
     * quarterly, yearly, etc.).
     *
     * If the user has no active subscription, falls back to the current
     * calendar month (1st to last day of month).
     *
     * @param int      $userID    The user to look up (FK to tblUsers)
     * @param int|null $partnerID Optional partner context for partner-specific subscriptions
     *
     * @return array{start: string, end: string} Billing period dates in 'YYYY-MM-DD' format
     *
     * @see https://www.php.net/manual/en/class.datetime.php (DateTime manipulation)
     * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html
     */
    public static function getBillingPeriod(int $userID, ?int $partnerID = null): array
    {
        try {
            // 🔍 Look up the user's active subscription
            // Checks for active, trial, or past_due subscriptions (all still "in use")
            $sql = "SELECT nextBillingDate, billingCycle
                    FROM tblSubscriptions
                    WHERE userID = ?
                      AND paymentStatus IN ('active', 'trial', 'past_due')";

            $params = [$userID];
            $types = 'i';

            // 🏢 Filter by partner if specified
            if ($partnerID !== null) {
                $sql .= " AND partnerID = ?";
                $params[] = $partnerID;
                $types .= 'i';
            }

            // 📋 Get the most recently created subscription if multiple exist
            $sql .= " ORDER BY createdAt DESC LIMIT 1";

            $subscription = Database::fetchOne($sql, $params, $types);

            // 📅 If we have an active subscription, derive the period from it
            if ($subscription && !empty($subscription['nextBillingDate'])) {
                $nextBilling = new \DateTime($subscription['nextBillingDate']);
                $billingCycle = $subscription['billingCycle'] ?? 'monthly';

                // 🔄 Calculate the period start by subtracting one billing cycle
                // from the nextBillingDate. The period runs from the start date
                // up to (but not including) the next billing date.
                // @see https://www.php.net/manual/en/datetime.sub.php
                $periodEnd = clone $nextBilling;
                $periodEnd->modify('-1 day'); // End date is the day before next billing

                $periodStart = clone $nextBilling;

                // 📏 Subtract the appropriate interval based on billing cycle type
                switch ($billingCycle) {
                    case 'monthly':
                        $periodStart->modify('-1 month');
                        break;
                    case 'quarterly':
                        $periodStart->modify('-3 months');
                        break;
                    case 'yearly':
                        $periodStart->modify('-1 year');
                        break;
                    case 'lifetime':
                    case 'one_time':
                        // 🔄 For lifetime/one-time, use the current calendar month
                        // as a reasonable default for usage tracking granularity
                        $periodStart = new \DateTime('first day of this month');
                        $periodEnd = new \DateTime('last day of this month');
                        break;
                    default:
                        // ⚠️ Unknown cycle — fall back to monthly
                        $periodStart->modify('-1 month');
                        break;
                }

                return [
                    'start' => $periodStart->format('Y-m-d'),
                    'end'   => $periodEnd->format('Y-m-d')
                ];
            }

            // 📅 Fallback: no active subscription — use the current calendar month
            // @see https://www.php.net/manual/en/datetime.format.php
            $now = new \DateTime();

            return [
                'start' => $now->format('Y-m-01'),
                'end'   => $now->format('Y-m-t')  // 't' = last day of the month
            ];

        } catch (\Exception $e) {
            // 🚨 Log the error and fall back to current calendar month
            ErrorLogger::logError(
                'usage_billing_period_error',
                'Failed to determine billing period: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['userID' => $userID, 'partnerID' => $partnerID]
            );

            // 📅 Safe fallback — current calendar month
            $now = new \DateTime();
            return [
                'start' => $now->format('Y-m-01'),
                'end'   => $now->format('Y-m-t')
            ];
        }
    }

    // ========================================================================
    // ✅ RECORD LIFECYCLE MANAGEMENT
    // ========================================================================

    /**
     * ✅ Mark usage records as processed for a billing period
     *
     * Called by BillingScheduler after usage charges have been calculated and
     * applied to an invoice. Sets isProcessed = 1 and processedAt = NOW() on
     * all matching records so they are not double-counted in future billing runs.
     *
     * @param int      $userID             The user whose records to mark
     * @param string   $billingPeriodStart Period start date (format: 'YYYY-MM-DD')
     * @param string   $billingPeriodEnd   Period end date (format: 'YYYY-MM-DD')
     * @param int|null $partnerID          Partner context (null = all partners)
     *
     * @return int Number of records marked as processed
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/update.html
     * @see https://www.php.net/manual/en/mysqli-stmt.affected-rows.php
     */
    public static function markRecordsProcessed(
        int $userID,
        string $billingPeriodStart,
        string $billingPeriodEnd,
        ?int $partnerID = null
    ): int {
        try {
            // 🏗️ Build the UPDATE query to mark matching records as processed
            $sql = "UPDATE tblUsageRecords
                    SET isProcessed = 1, processedAt = NOW()
                    WHERE userID = ?
                      AND billingPeriodStart = ?
                      AND billingPeriodEnd = ?
                      AND isProcessed = 0";

            $params = [$userID, $billingPeriodStart, $billingPeriodEnd];
            $types = 'iss';

            // 🏢 Filter by partner if specified
            if ($partnerID !== null) {
                $sql .= " AND partnerID = ?";
                $params[] = $partnerID;
                $types .= 'i';
            }

            Database::query($sql, $params, $types);

            // 🔢 Get the number of rows affected by the UPDATE
            // @see https://www.php.net/manual/en/mysqli.affected-rows.php
            $affectedRows = Database::getAffectedRows();

            // 📋 Log the processing activity
            if ($affectedRows > 0) {
                ActivityLogger::log(
                    $userID,
                    'usage_records_processed',
                    'payment',
                    'info',
                    'Marked ' . $affectedRows . ' usage records as processed'
                        . ' for period ' . $billingPeriodStart . ' to ' . $billingPeriodEnd,
                    [
                        'userID'             => $userID,
                        'billingPeriodStart' => $billingPeriodStart,
                        'billingPeriodEnd'   => $billingPeriodEnd,
                        'partnerID'          => $partnerID,
                        'recordsProcessed'   => $affectedRows
                    ]
                );
            }

            return $affectedRows;

        } catch (\Exception $e) {
            // 🚨 Log the error — this is critical as it could lead to double billing
            ErrorLogger::logError(
                'usage_mark_processed_error',
                'Failed to mark usage records as processed: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                [
                    'userID'             => $userID,
                    'billingPeriodStart' => $billingPeriodStart,
                    'billingPeriodEnd'   => $billingPeriodEnd,
                    'partnerID'          => $partnerID
                ],
                'critical'
            );

            return 0;
        }
    }

    /**
     * 🧹 Purge old processed usage records
     *
     * Deletes usage records that have been processed and are older than the
     * specified retention period. This helps manage database size for
     * high-volume usage tracking while complying with data retention policies.
     *
     * Only records where isProcessed = 1 are eligible for deletion. Unprocessed
     * records are never purged to prevent data loss before billing.
     *
     * ⚠️ This is a destructive operation — deleted records cannot be recovered.
     * Ensure billing summaries have been generated before purging.
     *
     * @param int $retentionDays Number of days to retain processed records
     *                           (default: 365 days / 1 year)
     *
     * @return int Number of records deleted
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/delete.html
     * @see https://gdpr-info.eu/art-5-gdpr/ (GDPR data minimisation principle)
     */
    public static function purgeOldRecords(int $retentionDays = self::DEFAULT_RETENTION_DAYS): int
    {
        try {
            // 🛡️ Validate retention period — minimum 30 days to prevent accidental purging
            if ($retentionDays < 30) {
                ErrorLogger::logError(
                    'usage_purge_invalid_retention',
                    'Retention period too short: ' . $retentionDays . ' days (minimum: 30)',
                    __FILE__,
                    __LINE__,
                    ['retentionDays' => $retentionDays],
                    'warning'
                );
                $retentionDays = 30;
            }

            // 🧹 Delete processed records older than the retention period
            // Only isProcessed = 1 records are eligible — never delete unprocessed data
            // @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html
            $sql = "DELETE FROM tblUsageRecords
                    WHERE isProcessed = 1
                      AND processedAt IS NOT NULL
                      AND processedAt < DATE_SUB(NOW(), INTERVAL ? DAY)";

            Database::query($sql, [$retentionDays], 'i');

            // 🔢 Get the number of rows deleted
            $deletedCount = Database::getAffectedRows();

            // 📋 Log the purge operation — important for audit compliance
            if ($deletedCount > 0) {
                ActivityLogger::log(
                    null,
                    'usage_records_purged',
                    'payment',
                    'info',
                    'Purged ' . $deletedCount . ' processed usage records older than '
                        . $retentionDays . ' days',
                    [
                        'retentionDays' => $retentionDays,
                        'deletedCount'  => $deletedCount
                    ]
                );
            }

            return $deletedCount;

        } catch (\Exception $e) {
            // 🚨 Log the error
            ErrorLogger::logError(
                'usage_purge_error',
                'Failed to purge old usage records: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['retentionDays' => $retentionDays]
            );

            return 0;
        }
    }

    // ========================================================================
    // 📈 ADMIN STATISTICS
    // ========================================================================

    /**
     * 📈 Get aggregate usage statistics for admin dashboards
     *
     * Returns high-level statistics about the usage tracking system, useful
     * for admin overview panels and monitoring. Includes total record counts,
     * processing status, unique user counts, and top metrics by usage.
     *
     * @param int|null $partnerID Optional partner filter (null = system-wide stats)
     *
     * @return array{
     *     totalRecords: int,
     *     unprocessedRecords: int,
     *     processedRecords: int,
     *     uniqueUsers: int,
     *     activeMetrics: int,
     *     topMetrics: array,
     *     oldestUnprocessed: string|null,
     *     newestRecord: string|null
     * }
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html
     */
    public static function getUsageStats(?int $partnerID = null): array
    {
        try {
            // 📊 Build the main statistics query
            // Uses conditional aggregation to get multiple stats in a single query pass
            // @see https://dev.mysql.com/doc/refman/8.0/en/control-flow-functions.html
            $sql = "SELECT
                        COUNT(*) AS totalRecords,
                        SUM(CASE WHEN isProcessed = 0 THEN 1 ELSE 0 END) AS unprocessedRecords,
                        SUM(CASE WHEN isProcessed = 1 THEN 1 ELSE 0 END) AS processedRecords,
                        COUNT(DISTINCT userID) AS uniqueUsers,
                        MIN(CASE WHEN isProcessed = 0 THEN recordedAt ELSE NULL END) AS oldestUnprocessed,
                        MAX(recordedAt) AS newestRecord
                    FROM tblUsageRecords";

            $params = [];
            $types = '';

            // 🏢 Filter by partner if specified
            if ($partnerID !== null) {
                $sql .= " WHERE partnerID = ?";
                $params[] = $partnerID;
                $types .= 'i';
            }

            $stats = Database::fetchOne($sql, $params, $types);

            // 📊 Get the count of active metrics
            $metricCountSql = "SELECT COUNT(*) AS activeMetrics
                               FROM tblUsageMetrics WHERE isActive = 1";
            $metricParams = [];
            $metricTypes = '';

            if ($partnerID !== null) {
                $metricCountSql .= " AND (partnerID IS NULL OR partnerID = ?)";
                $metricParams[] = $partnerID;
                $metricTypes .= 'i';
            }

            $metricCount = Database::fetchOne($metricCountSql, $metricParams, $metricTypes);

            // 🏆 Get the top 10 metrics by total usage (most active metrics)
            // Joins with tblUsageMetrics to get the human-readable name
            $topMetricsSql = "SELECT
                                  m.metricID,
                                  m.metricCode,
                                  m.metricName,
                                  m.unitLabel,
                                  COUNT(r.recordID) AS recordCount,
                                  COALESCE(SUM(r.quantity), 0) AS totalQuantity,
                                  COUNT(DISTINCT r.userID) AS uniqueUsers
                              FROM tblUsageMetrics m
                              LEFT JOIN tblUsageRecords r ON r.metricID = m.metricID";

            $topParams = [];
            $topTypes = '';

            if ($partnerID !== null) {
                $topMetricsSql .= " WHERE (m.partnerID IS NULL OR m.partnerID = ?)
                                    AND (r.partnerID = ? OR r.partnerID IS NULL)";
                $topParams[] = $partnerID;
                $topParams[] = $partnerID;
                $topTypes .= 'ii';
            }

            $topMetricsSql .= " GROUP BY m.metricID, m.metricCode, m.metricName, m.unitLabel
                                ORDER BY recordCount DESC
                                LIMIT 10";

            $topMetrics = Database::fetchAll($topMetricsSql, $topParams, $topTypes);

            // 🔢 Cast numeric fields for consistent types
            foreach ($topMetrics as &$metric) {
                $metric['metricID'] = (int)$metric['metricID'];
                $metric['recordCount'] = (int)$metric['recordCount'];
                $metric['totalQuantity'] = (float)$metric['totalQuantity'];
                $metric['uniqueUsers'] = (int)$metric['uniqueUsers'];
            }
            unset($metric); // 🧹 Break reference

            // 📋 Assemble the final statistics array
            return [
                'totalRecords'       => (int)($stats['totalRecords'] ?? 0),
                'unprocessedRecords' => (int)($stats['unprocessedRecords'] ?? 0),
                'processedRecords'   => (int)($stats['processedRecords'] ?? 0),
                'uniqueUsers'        => (int)($stats['uniqueUsers'] ?? 0),
                'activeMetrics'      => (int)($metricCount['activeMetrics'] ?? 0),
                'topMetrics'         => $topMetrics,
                'oldestUnprocessed'  => $stats['oldestUnprocessed'] ?? null,
                'newestRecord'       => $stats['newestRecord'] ?? null
            ];

        } catch (\Exception $e) {
            // 🚨 Log the error and return safe defaults
            ErrorLogger::logError(
                'usage_stats_error',
                'Failed to get usage statistics: ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                ['partnerID' => $partnerID]
            );

            // 📋 Return zeroed-out stats to avoid breaking the admin dashboard
            return [
                'totalRecords'       => 0,
                'unprocessedRecords' => 0,
                'processedRecords'   => 0,
                'uniqueUsers'        => 0,
                'activeMetrics'      => 0,
                'topMetrics'         => [],
                'oldestUnprocessed'  => null,
                'newestRecord'       => null
            ];
        }
    }
}
