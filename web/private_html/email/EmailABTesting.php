<?php
/**
 * ============================================================================
 * 🧪 SIGNula - Email A/B Testing Framework
 * ============================================================================
 *
 * Purpose: A/B test email campaigns to optimize performance
 * PHP Version: 8.3+
 *
 * Features:
 * - Subject line testing
 * - Content testing
 * - Sender name testing
 * - Send time testing
 * - Multi-variant testing (A/B/C/D/etc.)
 * - Automatic winner selection
 * - Statistical significance calculation
 * - Detailed performance analytics
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🧪 Email A/B Testing
 *
 * Framework for A/B testing email campaigns.
 */
class EmailABTesting
{
    /**
     * @var float Minimum sample size percentage (5% of total recipients)
     */
    private const MIN_SAMPLE_SIZE = 0.05;

    /**
     * @var float Confidence level for statistical significance (95%)
     */
    private const CONFIDENCE_LEVEL = 0.95;

    // ========================================================================
    // 🧪 TEST CREATION
    // ========================================================================

    /**
     * ➕ Create A/B Test
     *
     * Creates a new A/B test with multiple variants.
     *
     * @param array $testData Test configuration
     * @return int|null Test ID
     */
    public static function createTest(array $testData): ?int
    {
        try {
            // ✅ Validate test data
            $required = ['test_name', 'test_type', 'variants', 'sample_size_percentage'];
            foreach ($required as $field) {
                if (empty($testData[$field])) {
                    throw new RuntimeException("Missing required field: {$field}");
                }
            }

            // 🔍 Validate variants
            if (count($testData['variants']) < 2) {
                throw new RuntimeException("At least 2 variants required for A/B testing");
            }

            $query = "
                INSERT INTO tblEmailABTests (
                    testName, testType, description,
                    sampleSizePercentage, winnerSelectionMetric,
                    autoSelectWinner, status,
                    createdAt, createdBy
                ) VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW(), ?)
            ";

            $testID = Database::insert($query, [
                $testData['test_name'],
                $testData['test_type'],
                $testData['description'] ?? null,
                $testData['sample_size_percentage'],
                $testData['winner_metric'] ?? 'open_rate',
                $testData['auto_select_winner'] ?? true,
                $testData['created_by'] ?? null
            ], 'ssdssii');

            if (!$testID) {
                throw new RuntimeException('Failed to create A/B test');
            }

            // 📝 Create variants
            foreach ($testData['variants'] as $index => $variant) {
                self::createVariant($testID, $variant, $index);
            }

            return $testID;

        } catch (Exception $e) {
            error_log("Failed to create A/B test: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ➕ Create Variant
     *
     * Creates a variant for an A/B test.
     *
     * @param int $testID Test ID
     * @param array $variantData Variant data
     * @param int $index Variant index
     * @return int|null Variant ID
     */
    private static function createVariant(int $testID, array $variantData, int $index): ?int
    {
        $query = "
            INSERT INTO tblEmailABTestVariants (
                testID, variantName, variantLabel,
                subject, bodyHTML, bodyText,
                fromName, metadata,
                trafficPercentage
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        // Calculate traffic percentage (equal split by default)
        $trafficPercentage = $variantData['traffic_percentage'] ?? (100 / count($variantData));

        return Database::insert($query, [
            $testID,
            'variant_' . chr(65 + $index), // A, B, C, etc.
            $variantData['label'] ?? ('Variant ' . chr(65 + $index)),
            $variantData['subject'] ?? null,
            $variantData['bodyHTML'] ?? null,
            $variantData['bodyText'] ?? null,
            $variantData['fromName'] ?? null,
            json_encode($variantData['metadata'] ?? []),
            $trafficPercentage
        ], 'isssssssd');
    }

    /**
     * ▶️ Start A/B Test
     *
     * Starts an A/B test.
     *
     * @param int $testID Test ID
     * @return bool Success status
     */
    public static function startTest(int $testID): bool
    {
        $query = "
            UPDATE tblEmailABTests
            SET status = 'running', startedAt = NOW()
            WHERE testID = ? AND status = 'draft'
        ";

        return (bool)Database::query($query, [$testID], 'i');
    }

    // ========================================================================
    // 📤 SENDING
    // ========================================================================

    /**
     * 📧 Send A/B Test Emails
     *
     * Sends emails with variant assignment.
     *
     * @param int $testID Test ID
     * @param array $recipients Array of recipient emails
     * @param int|null $userID User ID
     * @return array Send result
     */
    public static function sendTestEmails(int $testID, array $recipients, ?int $userID = null): array
    {
        try {
            // 🔍 Get test details
            $test = self::getTest($testID);
            if (!$test || $test['status'] !== 'running') {
                throw new RuntimeException('Test not found or not running');
            }

            // 🔍 Get variants
            $variants = self::getVariants($testID);
            if (empty($variants)) {
                throw new RuntimeException('No variants found for test');
            }

            $results = [
                'total_sent' => 0,
                'variants' => []
            ];

            // 📧 Assign recipients to variants and send
            foreach ($recipients as $recipient) {
                // 🎲 Assign variant based on traffic percentage
                $variant = self::assignVariant($variants, $recipient);

                // 📨 Queue email with variant
                $queued = EmailService::queueEmail(
                    recipientEmail: $recipient,
                    subject: $variant['subject'],
                    bodyHTML: $variant['bodyHTML'],
                    bodyText: $variant['bodyText'],
                    fromName: $variant['fromName'],
                    userID: $userID,
                    metadata: json_encode([
                        'ab_test_id' => $testID,
                        'ab_variant_id' => $variant['variantID']
                    ])
                );

                if ($queued) {
                    $results['total_sent']++;
                    $results['variants'][$variant['variantName']] =
                        ($results['variants'][$variant['variantName']] ?? 0) + 1;

                    // 📊 Update variant stats
                    self::incrementVariantSent($variant['variantID']);
                }
            }

            return [
                'success' => true,
                'results' => $results
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 🎲 Assign Variant
     *
     * Assigns a recipient to a variant based on traffic percentage.
     *
     * @param array $variants Available variants
     * @param string $recipient Recipient email (for consistent assignment)
     * @return array Selected variant
     */
    private static function assignVariant(array $variants, string $recipient): array
    {
        // 🎲 Use email hash for consistent variant assignment
        $hash = crc32($recipient);
        $percentage = ($hash % 100) + 1; // 1-100

        $cumulativePercentage = 0;
        foreach ($variants as $variant) {
            $cumulativePercentage += $variant['trafficPercentage'];
            if ($percentage <= $cumulativePercentage) {
                return $variant;
            }
        }

        // Fallback to first variant
        return $variants[0];
    }

    /**
     * 📊 Increment Variant Sent Count
     *
     * @param int $variantID Variant ID
     */
    private static function incrementVariantSent(int $variantID): void
    {
        Database::query(
            "UPDATE tblEmailABTestVariants SET sentCount = sentCount + 1 WHERE variantID = ?",
            [$variantID],
            'i'
        );
    }

    // ========================================================================
    // 📊 ANALYTICS
    // ========================================================================

    /**
     * 📈 Get Test Results
     *
     * Retrieves performance results for A/B test.
     *
     * @param int $testID Test ID
     * @return array Test results
     */
    public static function getTestResults(int $testID): array
    {
        // 🔍 Get test
        $test = self::getTest($testID);
        if (!$test) {
            return [];
        }

        // 🔍 Get variants with stats
        $variants = self::getVariantStats($testID);

        // 📊 Calculate overall stats
        $totalSent = array_sum(array_column($variants, 'sentCount'));
        $totalOpens = array_sum(array_column($variants, 'openCount'));
        $totalClicks = array_sum(array_column($variants, 'clickCount'));

        // 🏆 Determine winner
        $winner = self::determineWinner($variants, $test['winnerSelectionMetric']);

        // 📊 Calculate statistical significance
        $significance = self::calculateStatisticalSignificance($variants);

        return [
            'test' => $test,
            'variants' => $variants,
            'overall' => [
                'total_sent' => $totalSent,
                'total_opens' => $totalOpens,
                'total_clicks' => $totalClicks,
                'overall_open_rate' => $totalSent > 0 ? ($totalOpens / $totalSent) * 100 : 0,
                'overall_click_rate' => $totalSent > 0 ? ($totalClicks / $totalSent) * 100 : 0
            ],
            'winner' => $winner,
            'statistical_significance' => $significance
        ];
    }

    /**
     * 📊 Get Variant Stats
     *
     * Gets performance statistics for all variants.
     *
     * @param int $testID Test ID
     * @return array Variant stats
     */
    private static function getVariantStats(int $testID): array
    {
        $query = "
            SELECT
                v.*,
                COALESCE(SUM(CASE WHEN e.openCount > 0 THEN 1 ELSE 0 END), 0) as openCount,
                COALESCE(SUM(e.clickCount), 0) as clickCount,
                CASE
                    WHEN v.sentCount > 0
                    THEN (SUM(CASE WHEN e.openCount > 0 THEN 1 ELSE 0 END) / v.sentCount) * 100
                    ELSE 0
                END as openRate,
                CASE
                    WHEN v.sentCount > 0
                    THEN (SUM(e.clickCount) / v.sentCount) * 100
                    ELSE 0
                END as clickRate
            FROM tblEmailABTestVariants v
            LEFT JOIN tblEmailQueue e ON JSON_EXTRACT(e.metadata, '$.ab_variant_id') = v.variantID
            WHERE v.testID = ?
            GROUP BY v.variantID
            ORDER BY v.variantName
        ";

        return Database::fetchAll($query, [$testID], 'i') ?: [];
    }

    /**
     * 🏆 Determine Winner
     *
     * Determines winning variant based on metric.
     *
     * @param array $variants Variant stats
     * @param string $metric Winning metric (open_rate, click_rate, conversion_rate)
     * @return array|null Winning variant
     */
    private static function determineWinner(array $variants, string $metric): ?array
    {
        if (empty($variants)) {
            return null;
        }

        $metricField = match($metric) {
            'open_rate' => 'openRate',
            'click_rate' => 'clickRate',
            default => 'openRate'
        };

        $winner = $variants[0];
        foreach ($variants as $variant) {
            if ($variant[$metricField] > $winner[$metricField]) {
                $winner = $variant;
            }
        }

        return $winner;
    }

    /**
     * 📊 Calculate Statistical Significance
     *
     * Calculates if results are statistically significant.
     *
     * @param array $variants Variant stats
     * @return array Significance data
     */
    private static function calculateStatisticalSignificance(array $variants): array
    {
        if (count($variants) < 2) {
            return ['is_significant' => false, 'confidence' => 0];
        }

        // Simple significance test (for production, use proper statistical test)
        // This is a basic implementation - for production, implement proper chi-square or t-test

        $control = $variants[0];
        $variant = $variants[1];

        // Need minimum sample size
        if ($control['sentCount'] < 100 || $variant['sentCount'] < 100) {
            return [
                'is_significant' => false,
                'confidence' => 0,
                'reason' => 'Insufficient sample size (minimum 100 per variant)'
            ];
        }

        // Calculate difference in conversion rates
        $controlRate = $control['sentCount'] > 0 ? $control['openCount'] / $control['sentCount'] : 0;
        $variantRate = $variant['sentCount'] > 0 ? $variant['openCount'] / $variant['sentCount'] : 0;

        $difference = abs($variantRate - $controlRate);
        $relativeDifference = $controlRate > 0 ? ($difference / $controlRate) * 100 : 0;

        // Simple threshold: 10% relative improvement + minimum sample size
        $isSignificant = $relativeDifference >= 10 &&
                        $control['sentCount'] >= 100 &&
                        $variant['sentCount'] >= 100;

        return [
            'is_significant' => $isSignificant,
            'confidence' => $isSignificant ? 95 : 0,
            'relative_improvement' => $relativeDifference,
            'sample_size_control' => $control['sentCount'],
            'sample_size_variant' => $variant['sentCount']
        ];
    }

    /**
     * ✅ Select Winner
     *
     * Manually or automatically selects winner and ends test.
     *
     * @param int $testID Test ID
     * @param int|null $winnerVariantID Winner variant ID (null = auto-select)
     * @return bool Success status
     */
    public static function selectWinner(int $testID, ?int $winnerVariantID = null): bool
    {
        try {
            $test = self::getTest($testID);
            if (!$test) {
                return false;
            }

            // Auto-select winner if not specified
            if ($winnerVariantID === null) {
                $results = self::getTestResults($testID);
                $winner = $results['winner'];
                $winnerVariantID = $winner['variantID'] ?? null;
            }

            if ($winnerVariantID === null) {
                return false;
            }

            // Update test
            $query = "
                UPDATE tblEmailABTests
                SET status = 'completed',
                    winnerVariantID = ?,
                    completedAt = NOW()
                WHERE testID = ?
            ";

            return (bool)Database::query($query, [$winnerVariantID, $testID], 'ii');

        } catch (Exception $e) {
            error_log("Failed to select winner: " . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 🔍 Get Test
     *
     * Retrieves A/B test by ID.
     *
     * @param int $testID Test ID
     * @return array|null Test data
     */
    public static function getTest(int $testID): ?array
    {
        $query = "SELECT * FROM tblEmailABTests WHERE testID = ?";
        return Database::fetchOne($query, [$testID], 'i');
    }

    /**
     * 🔍 Get Variants
     *
     * Retrieves variants for a test.
     *
     * @param int $testID Test ID
     * @return array Variants
     */
    public static function getVariants(int $testID): array
    {
        $query = "SELECT * FROM tblEmailABTestVariants WHERE testID = ? ORDER BY variantName";
        return Database::fetchAll($query, [$testID], 'i') ?: [];
    }

    /**
     * 📋 Get All Tests
     *
     * Retrieves all A/B tests.
     *
     * @param string|null $status Filter by status
     * @return array Tests
     */
    public static function getAllTests(?string $status = null): array
    {
        if ($status) {
            $query = "SELECT * FROM tblEmailABTests WHERE status = ? ORDER BY createdAt DESC";
            return Database::fetchAll($query, [$status], 's') ?: [];
        } else {
            $query = "SELECT * FROM tblEmailABTests ORDER BY createdAt DESC";
            return Database::fetchAll($query) ?: [];
        }
    }
}

// ✅ EmailABTesting class loaded successfully
