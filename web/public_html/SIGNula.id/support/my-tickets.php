<?php
/**
 * My Support Tickets Page
 * View user's submitted support tickets
 * 
 * @package SIGNula
 * @version 1.0.0
 */

// Start session and check authentication
session_start();
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'check-auth.php';

// Page configuration
$pageTitle = 'My Support Tickets - SIGNula';
$currentPage = 'support';

// Get status filter
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';

// Build query
$whereClause = "userID = ? AND isDeleted = FALSE";
$params = [$_SESSION['user_id']];
$types = 'i';

if ($statusFilter !== 'all') {
    $whereClause .= " AND status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

// Get tickets
$ticketsQuery = "
    SELECT 
        t.ticketID,
        t.ticketNumber,
        t.subject,
        t.category,
        t.priority,
        t.status,
        t.createdAt,
        t.updatedAt,
        t.slaDeadline,
        t.slaViolated,
        (SELECT COUNT(*) FROM tblSupportReplies WHERE ticketID = t.ticketID AND isDeleted = FALSE) as replyCount,
        (SELECT MAX(createdAt) FROM tblSupportReplies WHERE ticketID = t.ticketID AND isDeleted = FALSE) as lastReplyAt
    FROM tblSupportTickets t
    WHERE " . $whereClause . "
    ORDER BY 
        FIELD(t.status, 'New', 'Open', 'In Progress', 'Waiting on Customer', 'Waiting on Internal', 'Resolved', 'Closed', 'Cancelled'),
        t.createdAt DESC
";

$ticketsStmt = $mysqli->prepare($ticketsQuery);
$ticketsStmt->bind_param($types, ...$params);
$ticketsStmt->execute();
$ticketsResult = $ticketsStmt->get_result();
$tickets = $ticketsResult->fetch_all(MYSQLI_ASSOC);

// Get ticket counts by status
$countsQuery = "
    SELECT 
        status,
        COUNT(*) as count
    FROM tblSupportTickets
    WHERE userID = ? AND isDeleted = FALSE
    GROUP BY status
";
$countsStmt = $mysqli->prepare($countsQuery);
$countsStmt->bind_param('i', $_SESSION['user_id']);
$countsStmt->execute();
$countsResult = $countsStmt->get_result();
$counts = [];
$totalCount = 0;
while ($row = $countsResult->fetch_assoc()) {
    $counts[$row['status']] = $row['count'];
    $totalCount += $row['count'];
}

// Status badge colors
function getStatusBadge($status) {
    $badges = [
        'New' => 'bg-info',
        'Open' => 'bg-primary',
        'In Progress' => 'bg-warning',
        'Waiting on Customer' => 'bg-secondary',
        'Waiting on Internal' => 'bg-secondary',
        'Resolved' => 'bg-success',
        'Closed' => 'bg-dark',
        'Cancelled' => 'bg-danger'
    ];
    return $badges[$status] ?? 'bg-secondary';
}

// Priority badge colors
function getPriorityBadge($priority) {
    $badges = [
        'Low' => 'bg-secondary',
        'Normal' => 'bg-primary',
        'High' => 'bg-warning',
        'Urgent' => 'bg-danger'
    ];
    return $badges[$priority] ?? 'bg-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    
    <style>
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        
        .ticket-card {
            border-left: 4px solid;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .ticket-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .ticket-card.priority-urgent {
            border-left-color: #dc3545;
        }
        
        .ticket-card.priority-high {
            border-left-color: #ffc107;
        }
        
        .ticket-card.priority-normal {
            border-left-color: #0d6efd;
        }
        
        .ticket-card.priority-low {
            border-left-color: #6c757d;
        }
        
        .filter-pills .nav-link {
            border-radius: 20px;
            margin-right: 0.5rem;
        }
        
        .filter-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body class="bg-light">
    <!-- Page Header -->
    <header class="page-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h2 mb-2">My Support Tickets</h1>
                    <p class="mb-0 opacity-75">View and manage your support requests</p>
                </div>
                <div>
                    <a href="/support/ticket" class="btn btn-light">
                        <i class="fas fa-plus me-2"></i>New Ticket
                    </a>
                    <a href="https://signula.com/support" class="btn btn-outline-light ms-2">
                        <i class="fas fa-book me-2"></i>Help Center
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container pb-5">
        <!-- Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Pills -->
        <div class="mb-4">
            <ul class="nav filter-pills">
                <li class="nav-item">
                    <a class="nav-link <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="?status=all">
                        All Tickets <span class="badge bg-light text-dark"><?php echo $totalCount; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $statusFilter === 'Open' ? 'active' : ''; ?>" href="?status=Open">
                        Open <span class="badge bg-light text-dark"><?php echo $counts['Open'] ?? 0; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $statusFilter === 'In Progress' ? 'active' : ''; ?>" href="?status=In Progress">
                        In Progress <span class="badge bg-light text-dark"><?php echo $counts['In Progress'] ?? 0; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $statusFilter === 'Waiting on Customer' ? 'active' : ''; ?>" href="?status=Waiting on Customer">
                        Waiting on Me <span class="badge bg-light text-dark"><?php echo $counts['Waiting on Customer'] ?? 0; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $statusFilter === 'Resolved' ? 'active' : ''; ?>" href="?status=Resolved">
                        Resolved <span class="badge bg-light text-dark"><?php echo $counts['Resolved'] ?? 0; ?></span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Tickets List -->
        <?php if (empty($tickets)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="display-1 text-muted mb-3">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h3 class="h5">No tickets found</h3>
                    <p class="text-muted">You haven't submitted any support tickets<?php echo $statusFilter !== 'all' ? ' with this status' : ''; ?>.</p>
                    <a href="/support/ticket" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Submit Your First Ticket
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($tickets as $ticket): ?>
                    <div class="col-12 mb-3">
                        <div class="card ticket-card priority-<?php echo strtolower($ticket['priority']); ?> shadow-sm">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-start mb-2">
                                            <h3 class="h5 card-title mb-0 flex-grow-1">
                                                <a href="/support/view-ticket?id=<?php echo $ticket['ticketID']; ?>" class="text-decoration-none text-dark">
                                                    <?php echo htmlspecialchars($ticket['subject']); ?>
                                                </a>
                                            </h3>
                                            <span class="badge <?php echo getStatusBadge($ticket['status']); ?> ms-2">
                                                <?php echo htmlspecialchars($ticket['status']); ?>
                                            </span>
                                        </div>

                                        <div class="d-flex flex-wrap gap-2 mb-2">
                                            <span class="badge <?php echo getPriorityBadge($ticket['priority']); ?>">
                                                <i class="fas fa-flag me-1"></i><?php echo htmlspecialchars($ticket['priority']); ?>
                                            </span>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($ticket['category']); ?>
                                            </span>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-ticket-alt me-1"></i><?php echo htmlspecialchars($ticket['ticketNumber']); ?>
                                            </span>
                                        </div>

                                        <div class="text-muted small">
                                            <i class="far fa-clock me-1"></i>
                                            Created <?php echo date('M j, Y \a\t g:i A', strtotime($ticket['createdAt'])); ?>
                                            
                                            <?php if ($ticket['lastReplyAt']): ?>
                                                <span class="mx-2">•</span>
                                                <i class="far fa-comment me-1"></i>
                                                Last reply <?php echo date('M j, Y', strtotime($ticket['lastReplyAt'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                        <?php if ($ticket['replyCount'] > 0): ?>
                                            <div class="mb-2">
                                                <span class="badge bg-primary">
                                                    <i class="fas fa-comments me-1"></i>
                                                    <?php echo $ticket['replyCount']; ?> <?php echo $ticket['replyCount'] === 1 ? 'Reply' : 'Replies'; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($ticket['slaViolated']): ?>
                                            <div class="mb-2">
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>SLA Violated
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <a href="/support/view-ticket?id=<?php echo $ticket['ticketID']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Help Section -->
        <div class="mt-5">
            <div class="card">
                <div class="card-body">
                    <h3 class="h6 card-title">
                        <i class="fas fa-question-circle me-2 text-info"></i>Need help?
                    </h3>
                    <p class="card-text small mb-0">
                        Check our <a href="https://signula.com/support">Help Center</a> for answers to common questions, 
                        or visit the <a href="/docs">Documentation</a> for detailed guides.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
