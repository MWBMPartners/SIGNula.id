<?php
/**
 * ============================================================================
 * 📊 SIGNula - Activity Log Viewer
 * ============================================================================
 *
 * Purpose: View and search account activity history
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.5.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔒 Require login
requireLogin();

$pageTitle = 'Activity Log';
$user = getCurrentUser();
$userID = $_SESSION['userID'];

// 🔍 Get filter parameters
$filterType = $_GET['type'] ?? '';
$filterResult = $_GET['result'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// 📝 Handle export
if (isset($_GET['export'])) {
    $exportFormat = $_GET['export']; // 'csv' or 'json'

    // 🔍 Build query for export (no pagination)
    $whereConditions = ['userID = ?'];
    $params = [$userID];
    $types = 'i';

    if (!empty($filterType)) {
        $whereConditions[] = 'activityType = ?';
        $params[] = $filterType;
        $types .= 's';
    }

    if (!empty($filterResult)) {
        $whereConditions[] = 'activityResult = ?';
        $params[] = $filterResult;
        $types .= 's';
    }

    if (!empty($filterDateFrom)) {
        $whereConditions[] = 'loggedAt >= ?';
        $params[] = $filterDateFrom . ' 00:00:00';
        $types .= 's';
    }

    if (!empty($filterDateTo)) {
        $whereConditions[] = 'loggedAt <= ?';
        $params[] = $filterDateTo . ' 23:59:59';
        $types .= 's';
    }

    if (!empty($searchQuery)) {
        $whereConditions[] = '(activityType LIKE ? OR activityDetails LIKE ? OR ipAddress LIKE ?)';
        $searchTerm = '%' . $searchQuery . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'sss';
    }

    $whereClause = implode(' AND ', $whereConditions);
    $exportActivities = Database::fetchAll(
        "SELECT * FROM tblActivityLog WHERE {$whereClause} ORDER BY loggedAt DESC",
        $params,
        $types
    ) ?? [];

    if ($exportFormat === 'csv') {
        // 📄 CSV Export
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="activity-log-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date/Time', 'Activity Type', 'Result', 'Details', 'IP Address', 'User Agent']);

        foreach ($exportActivities as $activity) {
            fputcsv($output, [
                $activity['loggedAt'],
                $activity['activityType'],
                $activity['activityResult'] ?? '',
                $activity['activityDetails'] ?? '',
                $activity['ipAddress'] ?? '',
                $activity['userAgent'] ?? ''
            ]);
        }

        fclose($output);
        exit;

    } elseif ($exportFormat === 'json') {
        // 📄 JSON Export
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="activity-log-' . date('Y-m-d') . '.json"');

        echo json_encode($exportActivities, JSON_PRETTY_PRINT);
        exit;
    }
}

// 🔍 Build query with filters
$whereConditions = ['userID = ?'];
$params = [$userID];
$types = 'i';

if (!empty($filterType)) {
    $whereConditions[] = 'activityType = ?';
    $params[] = $filterType;
    $types .= 's';
}

if (!empty($filterResult)) {
    $whereConditions[] = 'activityResult = ?';
    $params[] = $filterResult;
    $types .= 's';
}

if (!empty($filterDateFrom)) {
    $whereConditions[] = 'loggedAt >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
    $types .= 's';
}

if (!empty($filterDateTo)) {
    $whereConditions[] = 'loggedAt <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
    $types .= 's';
}

if (!empty($searchQuery)) {
    $whereConditions[] = '(activityType LIKE ? OR activityDetails LIKE ? OR ipAddress LIKE ?)';
    $searchTerm = '%' . $searchQuery . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$whereClause = implode(' AND ', $whereConditions);

// 📊 Get total count
$countResult = Database::fetchOne(
    "SELECT COUNT(*) as total FROM tblActivityLog WHERE {$whereClause}",
    $params,
    $types
);
$totalActivities = $countResult['total'] ?? 0;
$totalPages = ceil($totalActivities / $perPage);

// 🔍 Get activities with pagination
$activities = Database::fetchAll(
    "SELECT * FROM tblActivityLog
     WHERE {$whereClause}
     ORDER BY loggedAt DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset]),
    $types . 'ii'
) ?? [];

// 📋 Get available activity types
$activityTypes = Database::fetchAll(
    "SELECT DISTINCT activityType FROM tblActivityLog WHERE userID = ? ORDER BY activityType",
    [$userID],
    'i'
) ?? [];

// 📊 Get activity statistics
$stats = [
    'total' => 0,
    'last_7_days' => 0,
    'last_30_days' => 0,
    'failed_logins' => 0
];

$statsData = Database::fetchOne(
    "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN loggedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as last_7_days,
        SUM(CASE WHEN loggedAt >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_30_days,
        SUM(CASE WHEN activityType = 'login' AND activityResult = 'failed' THEN 1 ELSE 0 END) as failed_logins
     FROM tblActivityLog WHERE userID = ?",
    [$userID],
    'i'
);

if ($statsData) {
    $stats = $statsData;
}

// 🎨 Include header
include SIGNULA_ROOT . '/private_html/layout/header.php';
?>

<div class="container mt-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-4">
            <?php include SIGNULA_ROOT . '/private_html/layout/settings-sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card shadow">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1 class="h3 mb-0">📊 Activity Log</h1>
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" id="exportMenu" data-bs-toggle="dropdown">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="exportMenu">
                                <li>
                                    <a class="dropdown-item" href="?export=csv<?php
                                        echo !empty($filterType) ? '&type=' . urlencode($filterType) : '';
                                        echo !empty($filterResult) ? '&result=' . urlencode($filterResult) : '';
                                        echo !empty($filterDateFrom) ? '&date_from=' . urlencode($filterDateFrom) : '';
                                        echo !empty($filterDateTo) ? '&date_to=' . urlencode($filterDateTo) : '';
                                        echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '';
                                    ?>">
                                        <i class="fas fa-file-csv"></i> Export as CSV
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="?export=json<?php
                                        echo !empty($filterType) ? '&type=' . urlencode($filterType) : '';
                                        echo !empty($filterResult) ? '&result=' . urlencode($filterResult) : '';
                                        echo !empty($filterDateFrom) ? '&date_from=' . urlencode($filterDateFrom) : '';
                                        echo !empty($filterDateTo) ? '&date_to=' . urlencode($filterDateTo) : '';
                                        echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '';
                                    ?>">
                                        <i class="fas fa-file-code"></i> Export as JSON
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card bg-primary text-white h-100">
                                <div class="card-body text-center">
                                    <h3 class="mb-0"><?php echo number_format($stats['total']); ?></h3>
                                    <small>Total Activities</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-info text-white h-100">
                                <div class="card-body text-center">
                                    <h3 class="mb-0"><?php echo number_format($stats['last_7_days']); ?></h3>
                                    <small>Last 7 Days</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-success text-white h-100">
                                <div class="card-body text-center">
                                    <h3 class="mb-0"><?php echo number_format($stats['last_30_days']); ?></h3>
                                    <small>Last 30 Days</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-<?php echo $stats['failed_logins'] > 0 ? 'danger' : 'secondary'; ?> text-white h-100">
                                <div class="card-body text-center">
                                    <h3 class="mb-0"><?php echo number_format($stats['failed_logins']); ?></h3>
                                    <small>Failed Logins</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-filter"></i> Filters
                                <?php if (!empty($filterType) || !empty($filterResult) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($searchQuery)): ?>
                                    <a href="?" class="btn btn-sm btn-outline-secondary float-end">
                                        <i class="fas fa-times"></i> Clear All
                                    </a>
                                <?php endif; ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3">
                                <!-- Search -->
                                <div class="col-md-12">
                                    <label for="search" class="form-label">Search</label>
                                    <input type="text" class="form-control" id="search" name="search"
                                           value="<?php echo htmlspecialchars($searchQuery); ?>"
                                           placeholder="Search by activity type, details, or IP address...">
                                </div>

                                <!-- Activity Type -->
                                <div class="col-md-4">
                                    <label for="type" class="form-label">Activity Type</label>
                                    <select class="form-select" id="type" name="type">
                                        <option value="">All Types</option>
                                        <?php foreach ($activityTypes as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type['activityType']); ?>"
                                                    <?php echo $filterType === $type['activityType'] ? 'selected' : ''; ?>>
                                                <?php echo ucwords(str_replace('_', ' ', $type['activityType'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Result -->
                                <div class="col-md-4">
                                    <label for="result" class="form-label">Result</label>
                                    <select class="form-select" id="result" name="result">
                                        <option value="">All Results</option>
                                        <option value="success" <?php echo $filterResult === 'success' ? 'selected' : ''; ?>>Success</option>
                                        <option value="failed" <?php echo $filterResult === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                    </select>
                                </div>

                                <!-- Date Range -->
                                <div class="col-md-2">
                                    <label for="date_from" class="form-label">From Date</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from"
                                           value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                                </div>

                                <div class="col-md-2">
                                    <label for="date_to" class="form-label">To Date</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to"
                                           value="<?php echo htmlspecialchars($filterDateTo); ?>">
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> Apply Filters
                                    </button>
                                    <?php if (!empty($filterType) || !empty($filterResult) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($searchQuery)): ?>
                                        <a href="?" class="btn btn-outline-secondary">
                                            <i class="fas fa-times"></i> Clear
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Activity List -->
                    <?php if (empty($activities)): ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle fs-3 mb-3 d-block"></i>
                            <p class="mb-0">No activity found matching your filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <small class="text-muted">
                                Showing <?php echo number_format($offset + 1); ?>-<?php echo number_format(min($offset + $perPage, $totalActivities)); ?>
                                of <?php echo number_format($totalActivities); ?> activities
                            </small>
                        </div>

                        <div class="list-group mb-4">
                            <?php foreach ($activities as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex align-items-start">
                                        <!-- Icon -->
                                        <div class="me-3">
                                            <?php
                                            $icon = match($activity['activityType']) {
                                                'login' => '🔐',
                                                'logout' => '🚪',
                                                'password_change' => '🔑',
                                                'password_reset' => '🔄',
                                                'email_change' => '📧',
                                                'mfa_enabled' => '📱',
                                                'mfa_disabled' => '❌',
                                                'mfa_verified' => '✅',
                                                'mfa_backup_codes_regenerated' => '🔑',
                                                'profile_update' => '👤',
                                                'oauth_account_linked' => '🔗',
                                                'oauth_account_unlinked' => '⛓️',
                                                'passkey_registered' => '🔐',
                                                'passkey_used' => '✅',
                                                'passkey_revoked' => '❌',
                                                'passwordless_login' => '📧',
                                                default => '📌'
                                            };
                                            echo '<span class="fs-3">' . $icon . '</span>';
                                            ?>
                                        </div>

                                        <!-- Content -->
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <div>
                                                    <strong><?php echo ucwords(str_replace('_', ' ', $activity['activityType'])); ?></strong>
                                                    <?php if ($activity['activityResult']): ?>
                                                        <span class="badge bg-<?php echo $activity['activityResult'] === 'success' ? 'success' : 'danger'; ?> ms-2">
                                                            <?php echo ucfirst($activity['activityResult']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo date('M j, Y g:i A', strtotime($activity['loggedAt'])); ?>
                                                    <span class="ms-1">(<?php echo timeAgo($activity['loggedAt']); ?>)</span>
                                                </small>
                                            </div>

                                            <?php if ($activity['activityDetails']): ?>
                                                <div class="text-muted small mb-2">
                                                    <?php echo htmlspecialchars($activity['activityDetails']); ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="d-flex gap-3 small text-muted">
                                                <?php if ($activity['ipAddress']): ?>
                                                    <span>
                                                        <i class="fas fa-network-wired"></i>
                                                        <?php echo htmlspecialchars($activity['ipAddress']); ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($activity['userAgent']): ?>
                                                    <span class="text-truncate" style="max-width: 400px;" title="<?php echo htmlspecialchars($activity['userAgent']); ?>">
                                                        <i class="fas fa-desktop"></i>
                                                        <?php echo htmlspecialchars(substr($activity['userAgent'], 0, 50)); ?>
                                                        <?php if (strlen($activity['userAgent']) > 50) echo '...'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Activity log pagination">
                                <ul class="pagination justify-content-center">
                                    <!-- Previous -->
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1;
                                            echo !empty($filterType) ? '&type=' . urlencode($filterType) : '';
                                            echo !empty($filterResult) ? '&result=' . urlencode($filterResult) : '';
                                            echo !empty($filterDateFrom) ? '&date_from=' . urlencode($filterDateFrom) : '';
                                            echo !empty($filterDateTo) ? '&date_to=' . urlencode($filterDateTo) : '';
                                            echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '';
                                        ?>">
                                            &laquo; Previous
                                        </a>
                                    </li>

                                    <!-- Page Numbers -->
                                    <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);

                                    for ($i = $startPage; $i <= $endPage; $i++):
                                    ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i;
                                                echo !empty($filterType) ? '&type=' . urlencode($filterType) : '';
                                                echo !empty($filterResult) ? '&result=' . urlencode($filterResult) : '';
                                                echo !empty($filterDateFrom) ? '&date_from=' . urlencode($filterDateFrom) : '';
                                                echo !empty($filterDateTo) ? '&date_to=' . urlencode($filterDateTo) : '';
                                                echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '';
                                            ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <!-- Next -->
                                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1;
                                            echo !empty($filterType) ? '&type=' . urlencode($filterType) : '';
                                            echo !empty($filterResult) ? '&result=' . urlencode($filterResult) : '';
                                            echo !empty($filterDateFrom) ? '&date_from=' . urlencode($filterDateFrom) : '';
                                            echo !empty($filterDateTo) ? '&date_to=' . urlencode($filterDateTo) : '';
                                            echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '';
                                        ?>">
                                            Next &raquo;
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Info Box -->
                    <div class="alert alert-info mt-4">
                        <h6 class="alert-heading">
                            <i class="fas fa-info-circle"></i> About Activity Log
                        </h6>
                        <ul class="mb-0 small">
                            <li>All account activities are logged for security purposes</li>
                            <li>Activity logs are retained for 90 days by default</li>
                            <li>Export your activity history anytime using the Export button</li>
                            <li>If you notice suspicious activity, change your password immediately</li>
                        </ul>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="/settings" class="btn btn-outline-secondary">
                            ← Back to Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// 🎨 Include footer
include SIGNULA_ROOT . '/private_html/layout/footer.php';
?>
