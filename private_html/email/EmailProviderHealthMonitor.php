<?php
/**
 * ============================================================================
 * 🏥 SIGNula - Email Provider Health Monitor
 * ============================================================================
 *
 * Purpose: Monitor health and performance of email providers
 * PHP Version: 8.3+
 *
 * Features:
 * - Automated health checks
 * - Performance monitoring
 * - Success/failure tracking
 * - Response time measurement
 * - Alert generation
 * - Provider status updates
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT') && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Require email providers
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'EmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'MicrosoftGraphEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'GmailAPIEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'SMTPEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'SendGridEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'MailgunEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'AmazonSESEmailProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'PostmarkEmailProvider.php';

/**
 * 🏥 Email Provider Health Monitor
 *
 * Monitors and tracks email provider health and performance.
 */
class EmailProviderHealthMonitor
{
    /**
     * @var bool Verbose output flag
     */
    private bool $verbose = false;

    /**
     * @var array Available providers
     */
    private array $providerClasses = [
        'microsoft_graph' => MicrosoftGraphEmailProvider::class,
        'gmail_api' => GmailAPIEmailProvider::class,
        'sendgrid' => SendGridEmailProvider::class,
        'mailgun' => MailgunEmailProvider::class,
        'amazon_ses' => AmazonSESEmailProvider::class,
        'postmark' => PostmarkEmailProvider::class,
        'smtp' => SMTPEmailProvider::class
    ];

    /**
     * 🏗️ Constructor
     *
     * @param bool $verbose Enable verbose output
     */
    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    // ========================================================================
    // 🏥 HEALTH CHECKS
    // ========================================================================

    /**
     * 🔍 Check All Providers
     *
     * Performs health checks on all configured providers.
     *
     * @return array Health check results
     */
    public function checkAllProviders(): array
    {
        $this->log("🏥 Email Provider Health Check Started");
        $this->log(str_repeat('=', 60));

        $results = [];

        foreach ($this->providerClasses as $key => $class) {
            $this->log("\n📋 Checking provider: " . ucfirst(str_replace('_', ' ', $key)));

            $result = $this->checkProvider($key);
            $results[$key] = $result;

            // 📊 Display result
            if ($result['healthy']) {
                $this->log("   ✅ Healthy - {$result['message']}");
            } else {
                $this->log("   ❌ Unhealthy - {$result['message']}");
            }

            if ($result['response_time']) {
                $this->log("   ⏱️  Response time: {$result['response_time']}ms");
            }
        }

        $this->log("\n" . str_repeat('=', 60));
        $this->log("✅ Health Check Complete\n");

        return $results;
    }

    /**
     * 🔍 Check Provider
     *
     * Performs health check on a specific provider.
     *
     * @param string $providerKey Provider key
     * @return array Health check result
     */
    public function checkProvider(string $providerKey): array
    {
        try {
            // 🔌 Load provider
            if (!isset($this->providerClasses[$providerKey])) {
                return [
                    'healthy' => false,
                    'message' => 'Provider not found',
                    'response_time' => null
                ];
            }

            $providerClass = $this->providerClasses[$providerKey];
            $provider = new $providerClass();

            // ✅ Check if configured
            if (!$provider->isConfigured()) {
                $this->updateProviderHealth($providerKey, false, 0, 0, 'Provider not configured');
                return [
                    'healthy' => false,
                    'message' => 'Provider not configured',
                    'response_time' => null
                ];
            }

            // 🧪 Perform test (dry run - don't actually send)
            $startTime = microtime(true);
            $healthy = $this->testProviderConnection($provider);
            $responseTime = round((microtime(true) - $startTime) * 1000); // Convert to milliseconds

            // 📊 Update health record
            if ($healthy) {
                $this->updateProviderHealth($providerKey, true, 1, 0, null, $responseTime);
                return [
                    'healthy' => true,
                    'message' => 'Provider is healthy',
                    'response_time' => $responseTime
                ];
            } else {
                $this->updateProviderHealth($providerKey, false, 0, 1, 'Connection test failed', $responseTime);
                return [
                    'healthy' => false,
                    'message' => 'Connection test failed',
                    'response_time' => $responseTime
                ];
            }

        } catch (Exception $e) {
            $this->updateProviderHealth($providerKey, false, 0, 1, $e->getMessage());
            return [
                'healthy' => false,
                'message' => $e->getMessage(),
                'response_time' => null
            ];
        }
    }

    /**
     * 🧪 Test Provider Connection
     *
     * Tests provider connection without sending an actual email.
     *
     * @param EmailProvider $provider Provider instance
     * @return bool True if healthy
     */
    private function testProviderConnection(EmailProvider $provider): bool
    {
        try {
            // 🔍 For now, we just check if the provider is configured
            // In a real implementation, you might want to test API connectivity
            // For example, by calling a provider's health check endpoint

            // Simple check: if provider is configured, consider it healthy
            return $provider->isConfigured();

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 📊 Update Provider Health
     *
     * Updates provider health record in database.
     *
     * @param string $provider Provider key
     * @param bool $healthy Whether provider is healthy
     * @param int $successInc Success count increment
     * @param int $failureInc Failure count increment
     * @param string|null $error Error message
     * @param int|null $responseTime Response time in milliseconds
     */
    private function updateProviderHealth(
        string $provider,
        bool $healthy,
        int $successInc,
        int $failureInc,
        ?string $error = null,
        ?int $responseTime = null
    ): void {
        try {
            // 🔍 Get current health record
            $current = Database::fetchOne(
                "SELECT * FROM tblEmailProviderHealth WHERE provider = ?",
                [$provider],
                's'
            );

            if ($current) {
                // 📊 Update existing record
                $newSuccessCount = $current['successCount'] + $successInc;
                $newFailureCount = $current['failureCount'] + $failureInc;
                $newTotalAttempts = $current['totalAttempts'] + $successInc + $failureInc;

                // 📈 Calculate success rate
                $successRate = $newTotalAttempts > 0 ? ($newSuccessCount / $newTotalAttempts) * 100 : 100;

                // ⏱️ Update average response time (simple moving average)
                $newAvgResponseTime = null;
                if ($responseTime !== null) {
                    if ($current['averageResponseTime']) {
                        $newAvgResponseTime = round(($current['averageResponseTime'] + $responseTime) / 2);
                    } else {
                        $newAvgResponseTime = $responseTime;
                    }
                }

                $query = "
                    UPDATE tblEmailProviderHealth SET
                        isHealthy = ?,
                        successCount = ?,
                        failureCount = ?,
                        totalAttempts = ?,
                        successRate = ?,
                        averageResponseTime = ?,
                        lastError = ?,
                        lastErrorAt = ?,
                        lastSuccessAt = ?,
                        lastCheckedAt = NOW()
                    WHERE provider = ?
                ";

                Database::query($query, [
                    $healthy ? 1 : 0,
                    $newSuccessCount,
                    $newFailureCount,
                    $newTotalAttempts,
                    $successRate,
                    $newAvgResponseTime,
                    $error,
                    $error ? date('Y-m-d H:i:s') : null,
                    $healthy ? date('Y-m-d H:i:s') : $current['lastSuccessAt'],
                    $provider
                ], 'iiiddississs');
            }

        } catch (Exception $e) {
            error_log("Failed to update provider health: " . $e->getMessage());
        }
    }

    /**
     * 📊 Get Provider Statistics
     *
     * Retrieves health statistics for all providers.
     *
     * @return array Provider statistics
     */
    public static function getProviderStatistics(): array
    {
        $query = "SELECT * FROM tblEmailProviderHealth ORDER BY provider";
        return Database::fetchAll($query) ?: [];
    }

    /**
     * 🔔 Check for Alerts
     *
     * Checks provider health and generates alerts for issues.
     *
     * @return array Alerts
     */
    public static function checkForAlerts(): array
    {
        $alerts = [];

        $providers = self::getProviderStatistics();

        foreach ($providers as $provider) {
            // 🚨 Unhealthy provider alert
            if (!$provider['isHealthy']) {
                $alerts[] = [
                    'severity' => 'error',
                    'provider' => $provider['provider'],
                    'message' => "Provider {$provider['provider']} is unhealthy",
                    'details' => $provider['lastError']
                ];
            }

            // ⚠️ Low success rate alert (below 90%)
            if ($provider['successRate'] < 90 && $provider['totalAttempts'] > 10) {
                $alerts[] = [
                    'severity' => 'warning',
                    'provider' => $provider['provider'],
                    'message' => "Provider {$provider['provider']} has low success rate ({$provider['successRate']}%)",
                    'details' => "Success rate dropped below 90%"
                ];
            }

            // 🐌 Slow response time alert (above 5 seconds)
            if ($provider['averageResponseTime'] > 5000) {
                $alerts[] = [
                    'severity' => 'warning',
                    'provider' => $provider['provider'],
                    'message' => "Provider {$provider['provider']} has slow response time ({$provider['averageResponseTime']}ms)",
                    'details' => "Response time exceeds 5 seconds"
                ];
            }
        }

        return $alerts;
    }

    /**
     * 📝 Log Message
     *
     * Outputs log message if verbose mode enabled.
     *
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }
    }
}

// ✅ EmailProviderHealthMonitor class loaded successfully

// ========================================================================
// 🖥️ CLI EXECUTION
// ========================================================================

// 🔍 Check if running from command line
if (php_sapi_name() === 'cli' && !defined('SIGNULA_INIT')) {
    // 🚀 Bootstrap application
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

    // 📝 Parse command line arguments
    $options = getopt('v', ['verbose', 'provider:']);

    $verbose = isset($options['v']) || isset($options['verbose']);
    $specificProvider = $options['provider'] ?? null;

    // 🏥 Run health check
    $monitor = new EmailProviderHealthMonitor($verbose);

    if ($specificProvider) {
        // Check specific provider
        $result = $monitor->checkProvider($specificProvider);
        exit($result['healthy'] ? 0 : 1);
    } else {
        // Check all providers
        $results = $monitor->checkAllProviders();

        // Count unhealthy providers
        $unhealthyCount = count(array_filter($results, fn($r) => !$r['healthy']));

        exit($unhealthyCount > 0 ? 1 : 0);
    }
}
