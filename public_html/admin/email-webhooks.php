<?php
/**
 * ============================================================================
 * 🔗 SIGNula - Email Webhook Management
 * ============================================================================
 *
 * Purpose: Manage email provider webhooks
 * PHP Version: 8.3+
 *
 * Features:
 * - View webhook configurations
 * - Test webhook endpoints
 * - View webhook logs
 * - Configure webhook URLs
 * - Monitor webhook health
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Check if user is logged in and is admin
requireLogin();

if (!isAdmin()) {
    http_response_code(403);
    die('Access denied. Admin privileges required.');
}

// 📚 Load required classes
require_once SIGNULA_ROOT . '/private_html/email/EmailWebhookHandler.php';

// 🎯 Get current page title
$pageTitle = 'Email Webhook Management';

// 🔍 Get webhook logs
$webhookLogs = getWebhookLogs(50);

// 📊 Get webhook statistics
$webhookStats = getWebhookStatistics();

/**
 * 📋 Get Webhook Logs
 *
 * @param int $limit Number of logs to retrieve
 * @return array Webhook logs
 */
function getWebhookLogs(int $limit = 50): array
{
    $query = "
        SELECT
            el.*,
            eq.recipientEmail,
            eq.subject
        FROM tblErrorLog el
        LEFT JOIN tblEmailQueue eq ON JSON_EXTRACT(el.contextData, '$.email_id') = eq.emailID
        WHERE el.errorContext = 'email_webhook'
        ORDER BY el.loggedAt DESC
        LIMIT ?
    ";

    return Database::fetchAll($query, [$limit], 'i') ?: [];
}

/**
 * 📊 Get Webhook Statistics
 *
 * @return array Statistics
 */
function getWebhookStatistics(): array
{
    // Get stats from last 24 hours
    $query = "
        SELECT
            JSON_EXTRACT(contextData, '$.provider') as provider,
            COUNT(*) as total_received,
            SUM(CASE WHEN errorSeverity = 'info' THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN errorSeverity IN ('warning', 'error', 'critical') THEN 1 ELSE 0 END) as failed
        FROM tblErrorLog
        WHERE errorContext = 'email_webhook'
        AND loggedAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY JSON_EXTRACT(contextData, '$.provider')
    ";

    return Database::fetchAll($query) ?: [];
}

/**
 * 🧪 Test Webhook
 *
 * Tests a webhook endpoint
 *
 * @param string $provider Provider name
 * @return array Test result
 */
function testWebhook(string $provider): array
{
    // This is a placeholder for webhook testing
    // In production, you would send a test payload to your webhook endpoint

    $webhookUrl = getWebhookUrl($provider);

    if (!$webhookUrl) {
        return [
            'success' => false,
            'error' => 'Webhook URL not configured'
        ];
    }

    return [
        'success' => true,
        'url' => $webhookUrl,
        'message' => 'Webhook endpoint is configured correctly'
    ];
}

/**
 * 🔗 Get Webhook URL
 *
 * Gets the webhook URL for a provider
 *
 * @param string $provider Provider name
 * @return string|null Webhook URL
 */
function getWebhookUrl(string $provider): ?string
{
    $baseUrl = getSetting('site.url', 'https://signula.id');
    return rtrim($baseUrl, '/') . "/webhooks/email?provider={$provider}";
}

// 🎨 Include header
include SIGNULA_ROOT . '/private_html/layout/admin-header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-2 d-md-block bg-light sidebar">
            <?php include SIGNULA_ROOT . '/private_html/layout/admin-sidebar.php'; ?>
        </nav>

        <!-- Main Content -->
        <main class="col-md-10 ms-sm-auto px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">🔗 <?php echo htmlspecialchars($pageTitle); ?></h1>
            </div>

            <!-- Webhook Statistics -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">📊 Webhook Statistics (Last 24 Hours)</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($webhookStats)): ?>
                                <p class="text-muted">No webhook activity in the last 24 hours.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Provider</th>
                                                <th>Total Received</th>
                                                <th>Successful</th>
                                                <th>Failed</th>
                                                <th>Success Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($webhookStats as $stat): ?>
                                                <?php
                                                $total = $stat['total_received'] ?? 0;
                                                $successful = $stat['successful'] ?? 0;
                                                $successRate = $total > 0 ? ($successful / $total) * 100 : 0;
                                                $successRateClass = $successRate >= 95 ? 'text-success' : ($successRate >= 80 ? 'text-warning' : 'text-danger');
                                                ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($stat['provider'] ?? 'Unknown'); ?></strong></td>
                                                    <td><?php echo number_format($total); ?></td>
                                                    <td class="text-success"><?php echo number_format($successful); ?></td>
                                                    <td class="text-danger"><?php echo number_format($stat['failed'] ?? 0); ?></td>
                                                    <td class="<?php echo $successRateClass; ?>">
                                                        <?php echo number_format($successRate, 1); ?>%
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Webhook Configuration -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">⚙️ Webhook Configuration</h5>
                        </div>
                        <div class="card-body">
                            <p>Configure these webhook URLs in your email provider's dashboard:</p>

                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Provider</th>
                                            <th>Webhook URL</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $providers = ['sendgrid', 'mailgun', 'postmark', 'amazon_ses'];
                                        foreach ($providers as $provider):
                                            $webhookUrl = getWebhookUrl($provider);
                                            $isConfigured = !empty(getSetting("email.{$provider}.api_key"));
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo ucfirst(str_replace('_', ' ', $provider)); ?></strong>
                                                </td>
                                                <td>
                                                    <code class="user-select-all"><?php echo htmlspecialchars($webhookUrl); ?></code>
                                                    <button class="btn btn-sm btn-outline-secondary ms-2" onclick="copyToClipboard('<?php echo htmlspecialchars($webhookUrl); ?>')">
                                                        📋 Copy
                                                    </button>
                                                </td>
                                                <td>
                                                    <?php if ($isConfigured): ?>
                                                        <span class="badge bg-success">Configured</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Not Configured</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary" onclick="testWebhook('<?php echo $provider; ?>')">
                                                        🧪 Test
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="alert alert-info mt-3">
                                <strong>ℹ️ Setup Instructions:</strong>
                                <ol class="mb-0 mt-2">
                                    <li>Copy the webhook URL for your provider</li>
                                    <li>Go to your email provider's webhook settings</li>
                                    <li>Add the webhook URL</li>
                                    <li>Select the events you want to track (delivered, bounced, opened, clicked, etc.)</li>
                                    <li>Save the configuration</li>
                                    <li>Use the "Test" button to verify it's working</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Webhook Logs -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">📋 Recent Webhook Activity</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($webhookLogs)): ?>
                                <p class="text-muted">No webhook activity yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Provider</th>
                                                <th>Event</th>
                                                <th>Email</th>
                                                <th>Subject</th>
                                                <th>Status</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($webhookLogs as $log): ?>
                                                <?php
                                                $contextData = json_decode($log['contextData'] ?? '{}', true);
                                                $provider = $contextData['provider'] ?? 'Unknown';
                                                $event = $contextData['event'] ?? 'Unknown';
                                                $severityClass = match($log['errorSeverity']) {
                                                    'info' => 'success',
                                                    'warning' => 'warning',
                                                    'error', 'critical' => 'danger',
                                                    default => 'secondary'
                                                };
                                                ?>
                                                <tr>
                                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['loggedAt'])); ?></td>
                                                    <td><?php echo htmlspecialchars($provider); ?></td>
                                                    <td><span class="badge bg-<?php echo $severityClass; ?>"><?php echo htmlspecialchars($event); ?></span></td>
                                                    <td><?php echo htmlspecialchars($log['recipientEmail'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($log['subject'] ?? '', 0, 50)); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $severityClass; ?>">
                                                            <?php echo htmlspecialchars($log['errorSeverity']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#logDetailModal" onclick="showLogDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                                            View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Log Detail Modal -->
<div class="modal fade" id="logDetailModal" tabindex="-1" aria-labelledby="logDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logDetailModalLabel">Webhook Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="logDetailContent">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * 📋 Copy to Clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Webhook URL copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}

/**
 * 🧪 Test Webhook
 */
function testWebhook(provider) {
    alert(`Testing webhook for ${provider}...\n\nIn production, this would send a test payload to verify the webhook is working correctly.`);
}

/**
 * 📝 Show Log Details
 */
function showLogDetails(log) {
    const content = document.getElementById('logDetailContent');
    const contextData = JSON.parse(log.contextData || '{}');

    let html = '<dl class="row">';
    html += `<dt class="col-sm-3">Timestamp:</dt><dd class="col-sm-9">${log.loggedAt}</dd>`;
    html += `<dt class="col-sm-3">Provider:</dt><dd class="col-sm-9">${contextData.provider || 'Unknown'}</dd>`;
    html += `<dt class="col-sm-3">Event Type:</dt><dd class="col-sm-9">${contextData.event || 'Unknown'}</dd>`;
    html += `<dt class="col-sm-3">Severity:</dt><dd class="col-sm-9"><span class="badge bg-info">${log.errorSeverity}</span></dd>`;

    if (log.recipientEmail) {
        html += `<dt class="col-sm-3">Recipient:</dt><dd class="col-sm-9">${log.recipientEmail}</dd>`;
    }

    if (log.subject) {
        html += `<dt class="col-sm-3">Subject:</dt><dd class="col-sm-9">${log.subject}</dd>`;
    }

    html += `<dt class="col-sm-3">Message:</dt><dd class="col-sm-9">${log.errorMessage || 'No message'}</dd>`;

    html += '<dt class="col-sm-3">Raw Data:</dt><dd class="col-sm-9"><pre class="bg-light p-2"><code>' + JSON.stringify(contextData, null, 2) + '</code></pre></dd>';

    html += '</dl>';

    content.innerHTML = html;
}
</script>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/private_html/layout/admin-footer.php';
?>
